<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Billing\Services\RenewalReminderSender;
use App\Listeners\Billing\QueueRenewalReminder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One renewal reminder (docs/13 §4.4) for one (subscription, period end, days left), queued by
 * {@see QueueRenewalReminder}. A learner gets a DM, an organization's
 * owners get an e-mail (+ a DM when they have a Telegram link). At most once per recipient across
 * retries: see {@see RenewalReminderSender}. Runs in the `default` queue.
 */
final class RenewalReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $daysLeft,
        public readonly CarbonImmutable $endsAt,
    ) {}

    public function handle(RenewalReminderSender $sender): void
    {
        $sender->send($this->subscriptionId, $this->daysLeft, $this->endsAt);
    }
}
