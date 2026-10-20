<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\BillingCatalog;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\CheckoutException;
use App\Domain\Billing\Support\OrgTiers;
use App\Domain\Billing\Support\SubscriptionLiveness;
use App\Models\Learner;
use App\Models\Offer;
use App\Models\Organization;
use App\Models\PaymentOrder;
use App\Models\ProviderTransaction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts a purchase (docs/13 §2.1, §4.4, §5): validates plan / period / provider / currency
 * against config('billing'), prices the order ON THE SERVER (list price, open offer), writes a
 * pending `payment_orders` row in a short transaction and only then — outside any transaction —
 * asks the provider for the payment link (docs/13 §3 rule 2).
 *
 * Lock order inside `create()`: subject row -> (read subscriptions) -> INSERT payment_orders.
 * No existing `payment_orders` row is ever locked here, so a webhook holding an order and
 * waiting for the subject cannot form a cycle with a checkout.
 */
final class CheckoutService
{
    public const VIA_TWA = 'twa';

    public const VIA_BOT = 'bot';

    public const VIA_ORG_PANEL = 'org_panel';

    public const VIA_ADMIN = 'admin';

    public function __construct(
        private readonly BillingProviderRegistry $providers,
        private readonly OfferService $offers,
        private readonly SubscriptionService $subscriptions,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @param  string  $period  `month` | `year` (config('billing.periods'))
     * @param  string|null  $tier  organization tariff (required for organizations, ignored for learners)
     * @param  string  $via  `twa` | `bot` | `org_panel` | `admin`
     * @param  Offer|null  $offer  explicit offer; null = the learner's best applicable open offer, if any
     *
     * @throws CheckoutException price_not_configured | provider_disabled | provider_not_supported | checkout_conflict | too_many_pending | offer_not_applicable | invalid_period | invalid_tier
     */
    public function create(
        Learner|Organization $subscriber,
        Plan $plan,
        string $period,
        SubscriptionProvider $provider,
        ?string $tier,
        string $via,
        ?Offer $offer = null,
        ?CarbonImmutable $now = null,
    ): PaymentOrder {
        $now ??= CarbonImmutable::now();
        $subscriberType = SubscriptionService::subscriberType($subscriber);

        if ($plan->rank() <= Plan::Free->rank()) {
            throw CheckoutException::priceNotConfigured("plan {$plan->value}");
        }

        if (! is_numeric(config("billing.periods.{$period}"))) {
            throw CheckoutException::invalidPeriod($period);
        }

        if (! $this->providers->isEnabled($provider)) {
            throw CheckoutException::providerDisabled($provider->value);
        }

        $currency = $provider->currency();
        if ($currency === null || ! $this->providers->for($provider)->supports($subscriberType, $currency)) {
            throw CheckoutException::providerNotSupported($provider->value, $subscriberType, $currency ?? '-');
        }

        $tier = $subscriber instanceof Organization ? $tier : null;
        $listAmount = $this->listPrice($subscriber, $plan, $tier, $period, $currency);

        if ($provider === SubscriptionProvider::TelegramStars && $period === 'month'
            && $listAmount > (int) config('billing.providers.telegram_stars.max_subscription_price', 10_000)) {
            throw CheckoutException::priceNotConfigured("a Stars subscription above the provider cap ({$listAmount} XTR)");
        }

        if ($offer === null && $subscriber instanceof Learner) {
            $offer = $this->offers->applicableFor($subscriber, $plan, $period, $now);
        }

        if ($offer !== null) {
            if (! ($subscriber instanceof Learner) || $offer->learner_id !== $subscriber->id
                || ! $offer->isOpenAt($now) || ! $offer->covers($plan->value, $period)) {
                throw CheckoutException::offerNotApplicable();
            }
        }

        $amount = $offer === null ? $listAmount : $this->offers->apply($offer, $listAmount, $currency);
        $maxPending = max(1, (int) config('billing.checkout.max_pending_per_subscriber', 3));
        $ttl = max(1, (int) config('billing.checkout.order_ttl_minutes', 1440));

        $order = DB::transaction(function () use ($subscriber, $subscriberType, $plan, $tier, $period, $provider, $currency, $amount, $listAmount, $offer, $via, $now, $maxPending, $ttl): PaymentOrder {
            $this->lockSubject($subscriber);

            $live = $this->subscriptions->liveFor($subscriber);
            if ($live !== null && $this->isRenewingElsewhere($live, $provider, $now)) {
                throw CheckoutException::conflict($live->provider);
            }

            $pending = PaymentOrder::query()
                ->where('subscriber_type', $subscriberType)
                ->where('subscriber_id', $subscriber->id)
                ->where('status', PaymentOrder::STATUS_PENDING)
                ->where('expires_at', '>', $now)
                ->count();

            if ($pending >= $maxPending) {
                throw CheckoutException::tooManyPending($maxPending);
            }

            $order = PaymentOrder::query()->create([
                'token' => Str::random(32),
                'subscriber_type' => $subscriberType,
                'subscriber_id' => $subscriber->id,
                'plan' => $plan->value,
                'tier' => $tier,
                'period' => $period,
                'provider' => $provider->value,
                'amount' => $amount,
                'list_amount' => $listAmount,
                'currency' => $currency,
                'offer_id' => $offer?->id,
                'status' => PaymentOrder::STATUS_PENDING,
                'expires_at' => $now->addMinutes($ttl),
                'created_via' => $via,
                'meta' => $subscriber instanceof Learner ? ['learner_locale' => $subscriber->interface_locale] : [],
            ]);

            $this->events->record(
                ProductEventName::CheckoutStarted,
                ['payment_order_id' => $order->id, 'plan' => $plan->value, 'tier' => $tier, 'period' => $period, 'provider' => $provider->value, 'currency' => $currency, 'amount' => $amount, 'discount_pct' => $offer?->discount_pct, 'via' => $via, 'subscriber_type' => $subscriberType],
                learner: $subscriber instanceof Learner ? $subscriber : null,
            );

            return $order;
        });

        try {
            $link = $this->providers->for($provider)->createCheckout($order);
        } catch (Throwable $e) {
            // The provider call is outside the transaction: a failure must not leave a payable order behind.
            $order->fill(['status' => PaymentOrder::STATUS_FAILED, 'meta' => [...$order->meta, 'checkout_error' => $e::class]])->save();
            Log::error('billing.checkout_failed', ['payment_order_id' => $order->id, 'provider' => $provider->value, 'exception' => $e::class, 'error' => Str::limit($e->getMessage(), 200)]);

            throw $e;
        }

        $order->fill([
            'provider_ref' => $link->url,
            'meta' => [...$order->meta, 'checkout' => ['kind' => $link->kind, 'expires_at' => $link->expiresAt->toIso8601String()]],
        ])->save();

        return $order;
    }

    /**
     * What the subscriber can buy right now (GET /billing/catalog): priced items per currency
     * with an enabled, supporting provider; the open offer; a summary of the live subscription.
     */
    public function catalog(Learner|Organization $subscriber, ?CarbonImmutable $now = null): BillingCatalog
    {
        $now ??= CarbonImmutable::now();
        $subscriberType = SubscriptionService::subscriberType($subscriber);

        $currencies = [];
        $providerKeys = [];
        foreach ($this->providers->enabled() as $provider) {
            $currency = $provider->currency();
            if ($currency !== null && $this->providers->for($provider)->supports($subscriberType, $currency)) {
                $currencies[$currency] = true;
                $providerKeys[] = $provider->value;
            }
        }

        $offer = null;
        $items = [];

        if ($subscriber instanceof Learner) {
            foreach ($this->periods() as $period) {
                $listPrices = [];
                $prices = [];
                $periodOffer = $this->offers->applicableFor($subscriber, Plan::Pro, $period, $now);

                foreach (array_keys($currencies) as $currency) {
                    $list = $this->configuredPrice(Plan::Pro, $period, (string) $currency);
                    if ($list === null) {
                        continue;
                    }
                    $listPrices[$currency] = $list;
                    $prices[$currency] = $periodOffer === null ? $list : $this->offers->apply($periodOffer, $list, (string) $currency);
                }

                if ($prices !== []) {
                    $items[] = ['plan' => Plan::Pro->value, 'tier' => null, 'period' => $period, 'prices' => $prices, 'list_prices' => $listPrices];
                    $offer ??= $periodOffer;
                }
            }
        } else {
            foreach (OrgTiers::keys() as $tier) {
                foreach ($this->periods() as $period) {
                    $prices = [];
                    foreach (array_keys($currencies) as $currency) {
                        $price = OrgTiers::price($tier, $period, (string) $currency);
                        if ($price !== null) {
                            $prices[$currency] = $price;
                        }
                    }
                    if ($prices !== []) {
                        $items[] = ['plan' => Plan::Pro->value, 'tier' => $tier, 'period' => $period, 'prices' => $prices, 'list_prices' => $prices];
                    }
                }
            }
        }

        $live = $this->subscriptions->liveFor($subscriber);

        return new BillingCatalog(
            items: $items,
            providers: $providerKeys,
            offer: $offer === null ? null : ['kind' => $offer->kind, 'discount_pct' => $offer->discount_pct, 'period' => $offer->period, 'expires_at' => CarbonImmutable::instance($offer->expires_at)->toIso8601String()],
            subscription: $live === null ? null : self::summarize($live, $now),
        );
    }

    /**
     * Scheduler (`billing:expire-orders`, every 15 min): pending orders past `expires_at` become
     * `expired`. A single UPDATE, no subject locks (docs/13 §3 rule 1); an order that a webhook
     * is applying at the same instant is row-locked by the applier and re-read after this UPDATE.
     */
    public function expirePending(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        // A provider transaction that is still open and younger than the provider's own timeout (Payme: 12 h)
        // keeps its order payable: the money may already be on its way to PerformTransaction (docs/13 §4.2).
        $openSince = $now->subMilliseconds((int) config('billing.providers.payme.transaction_timeout_ms', 43_200_000));

        return PaymentOrder::query()
            ->where('status', PaymentOrder::STATUS_PENDING)
            ->where('expires_at', '<=', $now)
            ->whereDoesntHave('providerTransactions', function (Builder $transactions) use ($openSince): void {
                $transactions->where('state', ProviderTransaction::STATE_CREATED)->where('created_at_provider', '>', $openSince);
            })
            ->update(['status' => PaymentOrder::STATUS_EXPIRED, 'updated_at' => $now]);
    }

    /** The order behind a provider's payload / account id; the token is compared as a string, never as an id. */
    public function findByToken(string $token): ?PaymentOrder
    {
        if ($token === '' || strlen($token) > 32) {
            return null;
        }

        /** @var PaymentOrder|null $order */
        $order = PaymentOrder::query()->where('token', $token)->first();

        return $order;
    }

    /**
     * Live-subscription summary for the catalog and `/me.entitlement.renewal`.
     *
     * @return array{status: string, provider: string, plan: string, ends_at: string|null, auto_renew: bool, can_cancel: bool, can_resume: bool}
     */
    public static function summarize(Subscription $live, CarbonImmutable $now): array
    {
        $provider = SubscriptionProvider::tryFrom($live->provider);
        $autoRenewing = $provider?->isAutoRenewing() ?? false;
        $ends = $live->current_period_ends_at === null ? null : CarbonImmutable::instance($live->current_period_ends_at);

        return [
            'status' => $live->status,
            'provider' => $live->provider,
            'plan' => $live->plan,
            'ends_at' => SubscriptionLiveness::liveUntil($live)?->toIso8601String(),
            'auto_renew' => $autoRenewing && $live->status === SubscriptionStatus::Active->value,
            'can_cancel' => $autoRenewing && $live->status === SubscriptionStatus::Active->value,
            'can_resume' => $live->status === SubscriptionStatus::Canceled->value && ($ends === null || $ends->greaterThan($now)),
        ];
    }

    /** @throws CheckoutException price_not_configured | invalid_tier */
    private function listPrice(Learner|Organization $subscriber, Plan $plan, ?string $tier, string $period, string $currency): int
    {
        if ($subscriber instanceof Organization) {
            if ($tier === null || ! OrgTiers::exists($tier)) {
                throw CheckoutException::invalidTier($tier);
            }

            return OrgTiers::price($tier, $period, $currency) ?? throw CheckoutException::priceNotConfigured("tier {$tier} / {$period} in {$currency}");
        }

        return $this->configuredPrice($plan, $period, $currency) ?? throw CheckoutException::priceNotConfigured("{$plan->value} / {$period} in {$currency}");
    }

    private function configuredPrice(Plan $plan, string $period, string $currency): ?int
    {
        $price = config("billing.prices.{$plan->value}.{$period}.{$currency}");

        return is_numeric($price) && (int) $price > 0 ? (int) $price : null;
    }

    /**
     * docs/13 §4.4: while a Stars subscription is live and still renewing, paying a period with
     * another provider would give two sources of renewal — refused until the renewal is canceled.
     */
    private function isRenewingElsewhere(Subscription $live, SubscriptionProvider $chosen, CarbonImmutable $now): bool
    {
        $liveProvider = SubscriptionProvider::tryFrom($live->provider);

        return $liveProvider !== null
            && $liveProvider !== $chosen
            && $liveProvider->isAutoRenewing()
            && $live->status === SubscriptionStatus::Active->value
            && SubscriptionLiveness::isLive($live, $now);
    }

    /** @return list<string> */
    private function periods(): array
    {
        /** @var array<string, mixed> $periods */
        $periods = (array) config('billing.periods', []);

        return array_map(static fn (string|int $k): string => (string) $k, array_keys($periods));
    }

    private function lockSubject(Learner|Organization $subscriber): void
    {
        $query = $subscriber instanceof Organization
            ? Organization::query()->whereKey($subscriber->id)
            : Learner::withTrashed()->whereKey($subscriber->id);

        $query->lockForUpdate()->value('id');
    }
}
