<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\SubscriptionProvider;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * The one rule for "does this subscription grant its plan at instant $now"
 * (docs/12 §5.1), shared by EntitlementResolver and SubscriptionService::expireDue
 * so the resolver never disagrees with the scheduler:
 *
 *   trialing / active : until `current_period_ends_at` (NULL = perpetual); for an
 *                       auto-renewing provider the grace period is added, so the
 *                       plan does not flap between the period end and the hourly
 *                       `subscriptions:expire` run that writes `past_due`;
 *   past_due          : until `grace_until`;
 *   canceled / expired: never.
 */
final class SubscriptionLiveness
{
    public static function isLive(Subscription $subscription, CarbonImmutable $now): bool
    {
        $status = SubscriptionStatus::tryFrom($subscription->status);

        if ($status === null || ! $status->isLive()) {
            return false;
        }

        if ($status === SubscriptionStatus::PastDue) {
            return $subscription->grace_until !== null && $subscription->grace_until->greaterThan($now);
        }

        $until = self::liveUntil($subscription);

        return $until === null || $until->greaterThan($now);
    }

    /** The instant the subscription stops granting its plan; null = no known end. */
    public static function liveUntil(Subscription $subscription): ?CarbonImmutable
    {
        if ($subscription->status === SubscriptionStatus::PastDue->value) {
            return $subscription->grace_until === null ? null : CarbonImmutable::instance($subscription->grace_until);
        }

        if ($subscription->current_period_ends_at === null) {
            return null;
        }

        $ends = CarbonImmutable::instance($subscription->current_period_ends_at);

        return self::providerHasGrace($subscription) ? $ends->addDays(self::graceDays()) : $ends;
    }

    public static function providerHasGrace(Subscription $subscription): bool
    {
        return SubscriptionProvider::tryFrom($subscription->provider)?->hasGrace() ?? false;
    }

    public static function graceDays(): int
    {
        return max(0, (int) config('billing.grace_days', 3));
    }
}
