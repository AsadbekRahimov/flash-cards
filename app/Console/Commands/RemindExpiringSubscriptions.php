<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Renewal reminders (docs/13 §4.4): raises SubscriptionRenewalDue once per (subscription, period end,
 * 3 / 1 day) for non-renewing live subscriptions; the listener queues the DM / e-mail. Scheduled daily
 * with `withoutOverlapping()`; a repeated run is harmless — the markers live in `subscriptions.meta`.
 */
final class RemindExpiringSubscriptions extends Command
{
    /** @var string */
    protected $signature = 'subscriptions:remind-expiring';

    /** @var string */
    protected $description = 'Queue renewal reminders for paid subscriptions that end in a few days and will not renew by themselves.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $raised = $subscriptions->remindExpiring(CarbonImmutable::now());

        $this->info("Renewal reminders queued: {$raised}");

        return self::SUCCESS;
    }
}
