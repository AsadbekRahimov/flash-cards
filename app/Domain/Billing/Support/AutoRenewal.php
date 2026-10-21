<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * "The provider is still going to charge the next period by itself" (docs/13 §4.4, "Реализация 2A.8"):
 * a live subscription of an auto-renewing provider (Stars) that is `active` or `past_due` — not
 * `canceled` (the renewal was stopped) and not over. While it runs, ANY other order of the learner
 * would give a second source of renewal or a second Telegram subscription, so checkout and every
 * provider pre-check refuse it until the renewal is canceled.
 */
final class AutoRenewal
{
    /**
     * The provider is going to charge this row again: an auto-renewing provider AND a recurring payment. A one-off
     * Stars invoice (the year) leaves a `telegram_stars` row that never renews — `meta.stars.recurring = false`.
     * A row without the flag (written before it existed) follows the period of its latest paid order: `month` renews.
     */
    public static function renews(Subscription $subscription): bool
    {
        if (! (SubscriptionProvider::tryFrom($subscription->provider)?->isAutoRenewing() ?? false)) {
            return false;
        }

        $stars = $subscription->meta['stars'] ?? null;

        if (is_array($stars) && array_key_exists('recurring', $stars)) {
            return (bool) $stars['recurring'];
        }

        $period = PaymentOrder::query()
            ->where('subscription_id', $subscription->id)
            ->where('status', PaymentOrder::STATUS_PAID)
            ->orderByDesc('id')
            ->value('period');

        return $period === null || $period === 'month';
    }

    public static function isRunning(Subscription $subscription, CarbonImmutable $now): bool
    {
        return self::renews($subscription)
            && in_array($subscription->status, [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value], true)
            && SubscriptionLiveness::isLive($subscription, $now);
    }

    /** The learner's running auto-renewing subscription, if any. Plain read (pre-checks run outside transactions). */
    public static function forLearner(int $learnerId, CarbonImmutable $now): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('subscriber_type', 'learner')
            ->where('subscriber_id', $learnerId)
            ->where('provider', SubscriptionProvider::TelegramStars->value)
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->orderBy('id')
            ->first();

        return $subscription !== null && self::isRunning($subscription, $now) ? $subscription : null;
    }
}
