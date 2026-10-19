<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * `subscriptions.status` (docs/12 §1.9, state machine §6.2).
 *
 * "Live" statuses are the ones covered by the partial unique index
 * `subscriptions_one_live_per_subscriber`; whether a live row actually grants
 * the plan right now is decided by SubscriptionLiveness (period / grace end).
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** @return list<string> */
    public static function liveValues(): array
    {
        return [self::Trialing->value, self::Active->value, self::PastDue->value];
    }

    public function isLive(): bool
    {
        return in_array($this->value, self::liveValues(), true);
    }
}
