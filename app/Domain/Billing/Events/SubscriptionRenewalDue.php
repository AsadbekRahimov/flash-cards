<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use Carbon\CarbonImmutable;

/**
 * A non-renewing live subscription ends in `$daysLeft` days (docs/13 §4.4). Raised once per
 * (subscription, period end, daysLeft) by SubscriptionService::remindExpiring after commit;
 * the listener (2A.6 `RenewalReminderJob`) sends the DM / e-mail and logs it in notifications_log.
 */
final readonly class SubscriptionRenewalDue
{
    public function __construct(
        public int $subscriptionId,
        public string $subscriberType,
        public int $subscriberId,
        public string $provider,
        public int $daysLeft,
        public CarbonImmutable $endsAt,
    ) {}
}
