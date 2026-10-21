<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Exceptions\ProviderUnavailableException;
use App\Domain\Billing\Services\BillingProviderRegistry;
use App\Domain\Billing\Services\CheckoutService;
use App\Domain\Billing\Services\SubscriptionService;
use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\PriceFormatter;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Domain\Organizations\DTO\OrgBillingPage;
use App\Domain\Organizations\DTO\OrgInvoice;
use App\Domain\Organizations\Exceptions\OrgBillingException;
use App\Mail\OrgInvoiceRequestMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Payment of a learning center from the `/org` cabinet (docs/13 §6.5) — OWNER only, every method
 * re-checks that (the Livewire page and the invoice route are thin):
 *
 *  - the tariff catalog from `config('billing.org_tiers')` with prices in UZS; a tier without a price
 *    (center_l) is never sold here — "request an invoice / contact us", the platform activates it by hand;
 *  - checkout through {@see CheckoutService} (`subscriber = organization`, `tier`, `created_via = org_panel`),
 *    Payme / Click only: Stars are a personal wallet, an organization is refused (`provider_not_supported`, 422);
 *  - the page data: subscription, seats and the excess after a downgrade, ways to pay;
 *  - the history of the organization's orders and payments, the printable invoice of an order, the
 *    "request an invoice" e-mail to the platform.
 *
 * A change of tariff is just another order with a `tier`: when it is paid `SubscriptionService::applyCharge`
 * sets `tier` / `seats_limit` and, after commit, `OrganizationMembershipService::reseat` seats the waitlist.
 * A lower tier unseats nobody — the page shows the excess (`overBy`) and new members wait.
 */
final class OrgBillingService
{
    public const AUDIT_INVOICE_REQUESTED = 'organization.invoice_requested';

    public const CURRENCY = 'UZS';

    /** Organizations pay through these only (docs/13 §0, §12 q.13). */
    private const PROVIDERS = [SubscriptionProvider::Payme, SubscriptionProvider::Click];

    /** One "request an invoice" per organization per 10 minutes and a few per day, whatever the tier / period. */
    private const INVOICE_THROTTLE_MINUTES = 10;

    private const INVOICE_DAILY_LIMIT = 5;

    private const COMMENT_LIMIT = 500;

    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly BillingProviderRegistry $providers,
        private readonly OrgOverviewService $overview,
        private readonly OrganizationMembershipService $memberships,
        private readonly OrgTenantScope $scope,
    ) {}

    /** @throws OrgBillingException not_owner */
    public function page(User $by, Organization $organization, ?CarbonImmutable $now = null): OrgBillingPage
    {
        $this->assertOwner($by, $organization);
        $now ??= CarbonImmutable::now();

        $subscription = $this->overview->subscription($organization, $now);
        $seats = $this->memberships->seatsSummary($organization, $now);
        $periods = $this->periods();

        $tiers = [];
        foreach (OrgTiers::keys() as $key) {
            $prices = [];
            foreach ($periods as $period) {
                $price = OrgTiers::price($key, $period, self::CURRENCY);
                if ($price !== null) {
                    $prices[$period] = $price;
                }
            }

            $limit = OrgTiers::seats($key);
            $purchasable = $prices !== [];
            $tiers[] = [
                'key' => $key,
                'seats' => $limit,
                'prices' => $prices,
                'purchasable' => $purchasable,
                'below_seated' => $limit !== null && $limit < $seats->seated,
                'online_allowed' => $purchasable && ($subscription === null || ! CheckoutService::changesLiveTier($subscription, $key, $now)),
            ];
        }

        return new OrgBillingPage(
            tier: $subscription?->tier,
            status: $subscription?->status,
            endsAt: $subscription === null ? null : SubscriptionLiveness::liveUntil($subscription),
            perpetual: $subscription !== null && $subscription->current_period_ends_at === null,
            seats: $seats,
            overBy: $seats->limit === null ? 0 : max(0, $seats->seated - $seats->limit),
            tiers: $tiers,
            periods: $periods,
            providers: array_map(static fn (SubscriptionProvider $p): string => $p->value, $this->payableProviders()),
            canRequestInvoice: $this->invoiceRecipient() !== null,
        );
    }

    /**
     * Opens an order for the tier / period and returns it with its checkout link (`provider_ref`).
     * The provider is called by {@see CheckoutService::create()} outside any transaction.
     *
     * @throws OrgBillingException not_owner | organization_suspended | tier_not_purchasable
     * @throws BillingException CheckoutException (provider_not_supported for Stars, provider_disabled, invalid_tier,
     *                          invalid_period, checkout_conflict, too_many_pending ...) | provider_unavailable
     */
    public function checkout(User $by, Organization $organization, string $tier, string $period, SubscriptionProvider $provider, ?CarbonImmutable $now = null): PaymentOrder
    {
        $this->assertOwner($by, $organization);

        if ($organization->status !== 'active') {
            throw OrgBillingException::organizationSuspended($organization->id);
        }

        if (! in_array($provider, self::PROVIDERS, true)) {
            throw CheckoutException::providerNotSupported($provider->value, 'organization', $provider->currency() ?? '-');
        }

        $this->assertSellable($tier, $period, mustHavePrice: true);

        try {
            $order = $this->checkout->create($organization, Plan::Pro, $period, $provider, $tier, CheckoutService::VIA_ORG_PANEL, now: $now);
        } catch (Throwable $e) {
            // CheckoutService has already failed the order and logged the cause.
            throw $this->isProviderFailure($e) ? ProviderUnavailableException::during('checkout', $provider->value) : $e;
        }

        if ($order->checkoutLink() === null) {
            Log::error('billing.checkout_without_link', ['payment_order_id' => $order->id, 'provider' => $provider->value]);

            throw ProviderUnavailableException::during('checkout', $provider->value);
        }

        return $order;
    }

    /**
     * The organization's orders and payments, newest first.
     *
     * @return array{orders: Collection<int, PaymentOrder>, payments: Collection<int, Payment>}
     *
     * @throws OrgBillingException not_owner
     */
    public function history(User $by, Organization $organization, int $limit = 20): array
    {
        $this->assertOwner($by, $organization);

        return [
            'orders' => PaymentOrder::query()
                ->where('subscriber_type', 'organization')
                ->where('subscriber_id', $organization->id)
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
            'payments' => Payment::query()
                ->where('subscriber_type', 'organization')
                ->where('subscriber_id', $organization->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get(),
        ];
    }

    /**
     * "Request an invoice": the owner's choice (tier, period, a comment) goes by e-mail to the platform
     * (`billing.invoice.request_email`, else `site.legal.email`); the platform answers by hand. Any
     * existing tier may be requested, `center_l` included. An organization may send one request per 10
     * minutes and at most {@see INVOICE_DAILY_LIMIT} a day, whatever the tier / period.
     *
     * @throws OrgBillingException not_owner | invoice_not_configured | invoice_request_throttled
     * @throws CheckoutException invalid_tier | invalid_period
     */
    public function requestInvoice(User $by, Organization $organization, string $tier, string $period, ?string $comment = null, ?string $ip = null): void
    {
        $this->assertOwner($by, $organization);
        $this->assertSellable($tier, $period, mustHavePrice: false);

        $recipient = $this->invoiceRecipient() ?? throw OrgBillingException::invoiceNotConfigured();

        $marker = "org-billing:invoice-request:{$organization->id}";
        if (! Cache::add($marker, 1, now()->addMinutes(self::INVOICE_THROTTLE_MINUTES))) {
            throw OrgBillingException::invoiceRequestThrottled();
        }

        $daily = "org-billing:invoice-request-day:{$organization->id}:".now()->format('Ymd');
        Cache::add($daily, 0, now()->addDay());
        if ((int) Cache::increment($daily) > self::INVOICE_DAILY_LIMIT) {
            Cache::decrement($daily);
            Cache::forget($marker);

            throw OrgBillingException::invoiceRequestThrottled();
        }

        $price = OrgTiers::price($tier, $period, self::CURRENCY);
        $comment = $comment === null ? null : mb_substr(trim($comment), 0, self::COMMENT_LIMIT);

        try {
            Mail::to($recipient)->send(new OrgInvoiceRequestMail(
                organizationName: $organization->name,
                organizationSlug: $organization->slug,
                requesterName: (string) $by->name,
                requesterEmail: (string) $by->email,
                tier: $tier,
                seats: OrgTiers::seats($tier),
                period: $period,
                amountLabel: $price === null ? null : PriceFormatter::plain($price, self::CURRENCY),
                comment: $comment === '' ? null : $comment,
            ));
        } catch (Throwable $e) {
            Cache::forget($marker);
            Cache::decrement($daily);

            throw $e;
        }

        AuditLog::create([
            'user_id' => $by->id,
            'action' => self::AUDIT_INVOICE_REQUESTED,
            'entity_type' => Organization::class,
            'entity_id' => $organization->id,
            'ip' => $ip,
            'meta' => ['tier' => $tier, 'period' => $period],
        ]);
    }

    /**
     * Data of the printable invoice of one of THIS organization's orders; any other order (another
     * organization's, a learner's) is `invoice_not_found`, indistinguishable from a missing one.
     *
     * @throws OrgBillingException not_owner | invoice_not_found
     */
    public function invoice(User $by, Organization $organization, int $orderId): OrgInvoice
    {
        $this->assertOwner($by, $organization);

        /** @var PaymentOrder|null $order */
        $order = PaymentOrder::query()
            ->whereKey($orderId)
            ->where('subscriber_type', 'organization')
            ->where('subscriber_id', $organization->id)
            ->first();

        if ($order === null) {
            throw OrgBillingException::invoiceNotFound($orderId, $organization->id);
        }

        $payer = array_filter(
            ['name' => $organization->name] + self::strings((array) ($organization->settings['contact'] ?? [])),
            static fn (string $v): bool => $v !== '',
        );

        return new OrgInvoice(
            number: sprintf('LF-%06d', $order->id),
            issuedAt: CarbonImmutable::instance($order->created_at ?? now())->setTimezone($organization->timezone),
            status: $order->status,
            seller: array_filter(self::strings((array) config('site.legal', [])), static fn (string $v): bool => $v !== ''),
            payer: $payer,
            tier: (string) $order->tier,
            seats: $order->tier === null ? null : OrgTiers::seats($order->tier),
            period: $order->period,
            periodDays: SubscriptionService::periodDays($order->period),
            amount: $order->amount,
            currency: $order->currency,
            provider: $order->provider,
        );
    }

    /** Where invoice requests go; null = nowhere configured. */
    public function invoiceRecipient(): ?string
    {
        foreach ([config('billing.invoice.request_email'), config('site.legal.email')] as $candidate) {
            $address = is_string($candidate) ? trim($candidate) : '';
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $address;
            }
        }

        return null;
    }

    /** @throws OrgBillingException not_owner */
    private function assertOwner(User $by, Organization $organization): void
    {
        if (! $this->scope->isOwner($by, $organization)) {
            throw OrgBillingException::notOwner($organization->id);
        }
    }

    /**
     * @throws CheckoutException invalid_tier | invalid_period
     * @throws OrgBillingException tier_not_purchasable
     */
    private function assertSellable(string $tier, string $period, bool $mustHavePrice): void
    {
        if (! OrgTiers::exists($tier)) {
            throw CheckoutException::invalidTier($tier);
        }

        if (! in_array($period, $this->periods(), true)) {
            throw CheckoutException::invalidPeriod($period);
        }

        if ($mustHavePrice && OrgTiers::price($tier, $period, self::CURRENCY) === null) {
            throw OrgBillingException::tierNotPurchasable($tier, $period);
        }
    }

    /**
     * Enabled providers that serve organizations in UZS.
     *
     * @return list<SubscriptionProvider>
     */
    private function payableProviders(): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn (SubscriptionProvider $p): bool => $this->providers->isEnabled($p) && $this->providers->for($p)->supports('organization', self::CURRENCY),
        ));
    }

    /** @return list<string> */
    private function periods(): array
    {
        return array_map(static fn (string|int $k): string => (string) $k, array_keys((array) config('billing.periods', [])));
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private static function strings(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $out;
    }

    /** A runtime failure of the provider adapter (HTTP); domain and database errors keep their own handling. */
    private function isProviderFailure(Throwable $e): bool
    {
        return $e instanceof RuntimeException && ! $e instanceof BillingException && ! $e instanceof QueryException;
    }
}
