<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\DTO\Checkout;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Exceptions\BillingException;
use App\Domain\Billing\Exceptions\ProviderUnavailableException;
use App\Domain\Billing\Exceptions\SubscriptionException;
use App\Domain\Billing\Support\PaidSubscriptionQuery;
use App\Models\Learner;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What a learner does with billing by themselves — the single entry point of the TWA
 * (`/api/twa/billing/*`, `/me`) and the bot (`/pro`): start a purchase, look at their order,
 * stop / restart the auto-renewal, and the two read models `/me` needs (paywall mode, renewal).
 * The money and lock-order rules stay in CheckoutService / SubscriptionService / PaymentApplier;
 * this class only orchestrates and translates provider outages into a domain error.
 */
final class LearnerBillingService
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly SubscriptionService $subscriptions,
        private readonly BillingProviderRegistry $providers,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * Creates the pending order and the provider's payment link.
     *
     * @param  string  $via  `twa` | `bot`
     *
     * @throws BillingException CheckoutException codes, or provider_unavailable when the provider failed
     */
    public function checkout(Learner $learner, Plan $plan, string $period, SubscriptionProvider $provider, string $via): Checkout
    {
        try {
            $order = $this->checkout->create($learner, $plan, $period, $provider, null, $via);
        } catch (Throwable $e) {
            // CheckoutService has already failed the order and logged the cause.
            throw self::isProviderFailure($e) ? ProviderUnavailableException::during('checkout', $provider->value) : $e;
        }

        $link = $order->checkoutLink();
        if ($link === null) {
            Log::error('billing.checkout_without_link', ['payment_order_id' => $order->id, 'provider' => $provider->value]);

            throw ProviderUnavailableException::during('checkout', $provider->value);
        }

        return new Checkout($order, $link);
    }

    /** The learner's own order by its token; someone else's order is indistinguishable from a missing one. */
    public function orderFor(Learner $learner, string $token): ?PaymentOrder
    {
        $order = $this->checkout->findByToken($token);

        if ($order === null || $order->subscriber_type !== 'learner' || $order->subscriber_id !== $learner->id) {
            return null;
        }

        return $order;
    }

    /**
     * Stops the auto-renewal: the provider first (outside any transaction, docs/13 §3 rule 2, §4.1),
     * then `active` -> `canceled`; the paid period stays live to its end.
     *
     * @throws BillingException not_cancelable | provider_capability | provider_unavailable
     */
    public function cancelAutoRenew(Learner $learner, ?string $ip = null): Subscription
    {
        $live = $this->subscriptions->liveFor($learner);

        if ($live === null) {
            throw new SubscriptionException(SubscriptionException::NOT_CANCELABLE, 'There is no live subscription to cancel.');
        }

        $provider = SubscriptionProvider::tryFrom($live->provider);

        if ($live->status !== SubscriptionStatus::Active->value || $provider === null || ! $provider->isAutoRenewing()) {
            throw SubscriptionException::notCancelable($live->id, "status is {$live->status}, provider {$live->provider}");
        }

        $this->callProvider($provider, 'cancel', fn () => $this->providers->for($provider)->cancelAutoRenew($live));

        return $this->subscriptions->cancel($live, $learner, $ip);
    }

    /**
     * @throws BillingException not_resumable | provider_capability | provider_unavailable
     */
    public function resumeAutoRenew(Learner $learner, ?string $ip = null, ?CarbonImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();
        $live = $this->subscriptions->liveFor($learner);

        if ($live === null) {
            throw new SubscriptionException(SubscriptionException::NOT_RESUMABLE, 'There is no live subscription to resume.');
        }

        $provider = SubscriptionProvider::tryFrom($live->provider);

        if ($live->status !== SubscriptionStatus::Canceled->value || $provider === null || ! $provider->isAutoRenewing()) {
            throw SubscriptionException::notResumable($live->id, "status is {$live->status}, provider {$live->provider}");
        }

        if ($live->current_period_ends_at !== null && ! $live->current_period_ends_at->greaterThan($now)) {
            throw SubscriptionException::notResumable($live->id, 'the period has ended');
        }

        $this->callProvider($provider, 'resume', fn () => $this->providers->for($provider)->resumeAutoRenew($live));

        return $this->subscriptions->resume($live, $learner, $ip, $now);
    }

    /**
     * `paywall` of `/me` (docs/13 §5): the configured mode, lowered to `coming_soon` when there is
     * nothing to sell (no enabled provider that serves a learner, or no price) — the owner may
     * switch the mode on before the providers are ready.
     *
     * @return array{mode: string, contact: string|null}
     */
    public function paywall(Learner $learner, ?CarbonImmutable $now = null): array
    {
        $paywall = $this->entitlements->paywall();

        if ($paywall['mode'] === 'purchase' && $this->checkout->catalog($learner, $now)->isEmpty()) {
            $paywall['mode'] = 'coming_soon';
        }

        return $paywall;
    }

    /**
     * `entitlement.renewal` of `/me`: the learner's own live subscription (a sponsor's is not theirs
     * to renew), `null` without one. `reminder_sent`: a renewal reminder was already raised for the
     * current period end.
     *
     * @return array{auto: bool, provider: string, ends_at: string|null, reminder_sent: bool}|null
     */
    public function renewal(Learner $learner, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();
        $live = $this->subscriptions->liveFor($learner);

        if ($live === null) {
            return null;
        }

        $summary = CheckoutService::summarize($live, $now);

        return [
            'auto' => $summary['auto_renew'],
            'provider' => $summary['provider'],
            'ends_at' => $summary['ends_at'],
            'reminder_sent' => $this->reminderSent($live),
        ];
    }

    /**
     * "Pro ran out" for `/me.entitlement.lapsed`: the learner is on Free now and their own paid
     * subscription expired within `billing.checkout.lapsed_banner_days` (the TWA shows "Pro ended —
     * renew"; it hides the banner by itself once dismissed). `null` while any Pro is in force
     * (own or a center's), when nothing expired lately, and for a subscription ended by hand
     * (refund / chargeback / admin: `meta.history` ends with an `ended` entry — not a lapse).
     *
     * @return array{provider: string, ended_at: string}|null
     */
    public function lapsed(Learner $learner, Entitlement $entitlement, ?CarbonImmutable $now = null): ?array
    {
        if ($entitlement->plan->rank() > 0) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $days = (int) config('billing.checkout.lapsed_banner_days', 14);

        if ($days <= 0) {
            return null;
        }

        /** @var Subscription|null $last */
        $last = Subscription::query()
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', $learner->id)
            ->where('status', SubscriptionStatus::Expired->value)
            ->whereIn('plan', PaidSubscriptionQuery::paidPlans())
            ->where('ended_at', '>=', $now->subDays($days))
            ->where('ended_at', '<=', $now)
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->ended_at === null) {
            return null;
        }

        $history = $last->meta['history'] ?? [];
        $latest = is_array($history) && $history !== [] ? end($history) : null;

        if (is_array($latest) && ($latest['action'] ?? null) === 'ended') {
            return null;
        }

        return ['provider' => $last->provider, 'ended_at' => CarbonImmutable::instance($last->ended_at)->toIso8601String()];
    }

    private function reminderSent(Subscription $live): bool
    {
        if ($live->current_period_ends_at === null) {
            return false;
        }

        $suffix = '@'.CarbonImmutable::instance($live->current_period_ends_at)->toIso8601String();
        $markers = $live->meta[SubscriptionService::META_RENEWAL_REMINDERS] ?? [];

        foreach (is_array($markers) ? $markers : [] as $marker) {
            if (is_string($marker) && str_ends_with($marker, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param callable(): void $call */
    private function callProvider(SubscriptionProvider $provider, string $operation, callable $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            if (! self::isProviderFailure($e)) {
                throw $e;
            }

            Log::error('billing.auto_renew_failed', ['provider' => $provider->value, 'operation' => $operation, 'exception' => $e::class]);

            throw ProviderUnavailableException::during($operation, $provider->value);
        }
    }

    /**
     * A runtime failure of the provider adapter (HTTP / Telegram API). Domain errors and database
     * errors are not "the provider is down" and keep their own handling.
     */
    private static function isProviderFailure(Throwable $e): bool
    {
        return $e instanceof RuntimeException && ! $e instanceof BillingException && ! $e instanceof QueryException;
    }
}
