<?php

declare(strict_types=1);

namespace App\Listeners\Billing;

use App\Domain\Billing\Events\SubscriptionRenewalDue;
use App\Jobs\RenewalReminderJob;
use Illuminate\Support\Facades\Cache;

/**
 * Turns {@see SubscriptionRenewalDue} into a queued {@see RenewalReminderJob}. The listener is
 * synchronous and cheap (the event is raised after commit by the daily command); the n-th job of a
 * day is delayed by floor(n / send_rate_per_second) seconds so a daily batch does not hit Telegram
 * in one burst (the same knob as the personal reminders). Auto-discovered (`app/Listeners`).
 */
final class QueueRenewalReminder
{
    public function handle(SubscriptionRenewalDue $event): void
    {
        RenewalReminderJob::dispatch(
            $event->subscriptionId,
            $event->daysLeft,
            $event->endsAt,
        )->delay($this->delaySeconds());
    }

    private function delaySeconds(): int
    {
        $rate = max(1, (int) config('learning.reminders.send_rate_per_second', 25));
        $key = 'billing:renewal-reminder:slot:'.now()->utc()->format('Ymd');

        Cache::add($key, 0, 90_000);
        $n = (int) Cache::increment($key);

        return intdiv(max(1, $n) - 1, $rate);
    }
}
