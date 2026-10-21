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
 * `capped` = the per-run cap was reached before every due learner was claimed.
 */
final class ReminderDispatchReport
{
    public int $claimed = 0;

    public int $queued = 0;

    public int $skippedStale = 0;

    public int $repaired = 0;

    public int $backfilled = 0;

    /** The run hit the per-run cap (`send_rate × 840`): the rest of the due learners waits for the next run. */
    public bool $capped = false;

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
