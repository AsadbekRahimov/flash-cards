<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Learners\Services\ReminderDispatcher;
use Illuminate\Console\Command;

/**
 * Queues personal reminders whose local time has come (docs/12 §7.5, docs/06 §6).
 *
 * Scheduled `everyFifteenMinutes()->withoutOverlapping()`. Safe to run twice or in
 * parallel: every learner is claimed by a conditional UPDATE of `next_reminder_at`.
 * Also backfills `next_reminder_at` for learners who have a reminder time but no
 * scheduled instant yet (capped per run).
 */
final class DispatchReminders extends Command
{
    /** @var string */
    protected $signature = 'reminders:dispatch';

    /** @var string */
    protected $description = 'Queue Telegram reminders for learners whose local reminder time has come.';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $report = $dispatcher->dispatchDue();

        $this->info(sprintf(
            'Reminders: claimed %d, queued %d, skipped stale %d, repaired %d, backfilled %d.',
            $report->claimed,
            $report->queued,
            $report->skippedStale,
            $report->repaired,
            $report->backfilled,
        ));

        return self::SUCCESS;
    }
}
