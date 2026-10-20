<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/**
 * `subscriptions.status` (docs/12 §1.9, state machine §6.2; docs/13 §2.1).
 *
 * "Live" statuses are the ones covered by the partial unique index
 * `subscriptions_one_live_per_subscriber`; whether a live row actually grants
 * the plan right now is decided by SubscriptionLiveness (period / grace end).
 *
 * `canceled` is live since 2A.1: the user stopped the auto-renewal, the paid
 * period still runs ("Pro until the end of the period", no grace afterwards).
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
        return [self::Trialing->value, self::Active->value, self::PastDue->value, self::Canceled->value];
    }

    public function isLive(): bool
    {
        return in_array($this->value, self::liveValues(), true);
    }
}
