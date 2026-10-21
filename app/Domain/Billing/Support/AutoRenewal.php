<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
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
    public static function isRunning(Subscription $subscription, CarbonImmutable $now): bool
    {
        return (SubscriptionProvider::tryFrom($subscription->provider)?->isAutoRenewing() ?? false)
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
