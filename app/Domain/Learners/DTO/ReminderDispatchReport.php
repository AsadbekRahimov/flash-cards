<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * Outcome of one `ReminderDispatcher::dispatchDue()` run (docs/12 §7.5).
 *
 * `claimed` = learners whose `next_reminder_at` this run moved forward (each exactly
 * once, however many dispatcher instances ran); of those, `queued` got a job and
 * `skippedStale` were too old to send (scheduler downtime, `dm_blocked_at` cleared
 * long after). `repaired` had `reminder_time = NULL` but a stale `next_reminder_at`.
 * `backfilled` = learners that had a reminder time but no `next_reminder_at` yet.
 */
final class ReminderDispatchReport
{
    public int $claimed = 0;

    public int $queued = 0;

    public int $skippedStale = 0;

    public int $repaired = 0;

    public int $backfilled = 0;

    /** @return array{claimed: int, queued: int, skipped_stale: int, repaired: int, backfilled: int} */
    public function toArray(): array
    {
        return [
            'claimed' => $this->claimed,
            'queued' => $this->queued,
            'skipped_stale' => $this->skippedStale,
            'repaired' => $this->repaired,
            'backfilled' => $this->backfilled,
        ];
    }
}
