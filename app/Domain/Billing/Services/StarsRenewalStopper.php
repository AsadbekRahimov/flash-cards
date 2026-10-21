<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Support\AutoRenewal;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stops Telegram's renewal when the Stars of a subscription payment are returned ("Реализация 2A.8", 🟡6):
 * `refundStarPayment` gives the money back but the Telegram subscription keeps charging the learner every
 * 30 days. Two steps around the refund, because ending the subscription (`applyRefund`) hides whether it renewed:
 *
 *   runningFor($chargeId)  BEFORE the refund is applied: the subscription (and ledger row) behind the charge, only if
 *                          its Stars renewal is still running;
 *   stop(...)              after the money is back, OUTSIDE any transaction (docs/13 §3 rule 2):
 *                          `editUserStarSubscription(is_canceled = true)` through the provider, then `active` ->
 *                          `canceled` locally when the row is still alive (a row the refund has just ended is left).
 *
 * A failure never breaks the refund: it is reported and logged (`billing.refund_autorenew_stop_failed`), the admin
 * cancels the subscription by hand (ADMIN_GUIDE).
 */
final class StarsRenewalStopper
{
    public function __construct(
        private readonly BillingProviderRegistry $providers,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{subscription_id: int, payment_id: int}|null
     */
    public function runningFor(string $chargeId, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now();

        /** @var Payment|null $charge */
        $charge = Payment::query()
            ->where('provider', SubscriptionProvider::TelegramStars->value)
            ->where('provider_charge_id', $chargeId)
            ->whereIn('kind', [Payment::KIND_CHARGE, Payment::KIND_RENEWAL])
            ->whereNotNull('subscription_id')
            ->orderByDesc('id')
            ->first(['id', 'subscription_id']);

        if ($charge === null || $charge->subscription_id === null) {
            return null;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->whereKey($charge->subscription_id)->first();

        return $subscription !== null && AutoRenewal::isRunning($subscription, $now)
            ? ['subscription_id' => $subscription->id, 'payment_id' => $charge->id]
            : null;
    }

    /**
     * @param  array{subscription_id: int, payment_id: int}|null  $running  {@see runningFor()}
     * @param  User|null  $by  the admin who refunded (audit of the local cancel); null = Telegram reported the refund
     */
    public function stop(?array $running, ?User $by = null, ?string $ip = null, ?CarbonImmutable $now = null): void
    {
        if ($running === null) {
            return;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()->whereKey($running['subscription_id'])->first();

        if ($subscription === null) {
            return;
        }

        try {
            $this->providers->for(SubscriptionProvider::TelegramStars)->cancelAutoRenew($subscription);
        } catch (Throwable $e) {
            report($e);
            Log::error('billing.refund_autorenew_stop_failed', ['payment_id' => $running['payment_id'], 'subscription_id' => $running['subscription_id'], 'by_user_id' => $by?->id, 'error' => $e::class]);

            return;
        }

        // Telegram no longer renews: mirror it. A row the refund has already ended (or any other state) has nothing to cancel.
        // Without an admin the system did it (not the learner): no audit entry, event `by = system`.
        try {
            $this->subscriptions->cancel($subscription, $by, $ip, $now);
        } catch (Throwable) {
            // Not `active` any more: nothing to cancel locally.
        }
    }
}
