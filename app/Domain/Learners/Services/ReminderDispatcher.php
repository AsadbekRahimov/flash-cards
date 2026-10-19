<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\ReminderDispatchReport;
use App\Jobs\SendLearnerReminderJob;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Turns `learners.next_reminder_at` into reminder jobs (docs/12 §7.5, docs/06 §6).
 * Runs every 15 minutes (`reminders:dispatch`, `withoutOverlapping`), but is
 * written so that two instances running at once — or the same minute replayed —
 * cannot remind anyone twice:
 *
 *  - selection: `next_reminder_at <= now AND dm_blocked_at IS NULL AND blocked_at IS NULL
 *    AND deleted_at IS NULL` — exactly the shape of the partial index
 *    `learners_next_reminder_at_idx` (PostgreSQL), chunked by id;
 *  - claim: one conditional `UPDATE ... SET next_reminder_at = <next occurrence>
 *    WHERE id = ? AND next_reminder_at = <the value we read>`. Only the instance whose
 *    UPDATE affected a row dispatches the job; the loser sees 0 rows and skips. The
 *    pointer moves BEFORE the job is queued, so a crash in between loses one day's
 *    reminder rather than doubling it. The next occurrence is computed from `now`,
 *    not from the stale value, so a scheduler that was down for days catches up in
 *    one run instead of firing once per missed day;
 *  - staleness: a claim older than `learning.reminders.max_delay_minutes` is not
 *    sent (the time of day is wrong by then) — the pointer still moves;
 *  - the job holds the second idempotency level (`notifications_log`, see
 *    {@see ReminderSender}).
 *
 * Backfill: learners who have a `reminder_time` but no `next_reminder_at` (rows created
 * before {@see LearnerRegistrar} started scheduling at creation, imports) get one here,
 * capped per run. Learners with `dm_blocked_at`, `blocked_at` or soft-deleted are left
 * alone — they are not reminded anyway. Whether the onboarding is finished does not
 * matter: §7.5 reminds a learner created from a group too; the first 403 marks them
 * `dm_blocked_at` and that is the end of it until they write to the bot.
 */
final class ReminderDispatcher
{
    public function __construct(private readonly LearnerClock $clock) {}

    public function dispatchDue(?CarbonImmutable $now = null): ReminderDispatchReport
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $report = new ReminderDispatchReport;

        $report->backfilled = $this->backfillMissing($now, $this->backfillLimit());

        $this->reminderable()
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', $now->format('Y-m-d H:i:s'))
            ->chunkById($this->batchSize(), function (Collection $learners) use ($now, $report): void {
                foreach ($learners as $learner) {
                    $this->claim($learner, $now, $report);
                }
            });

        return $report;
    }

    /**
     * Gives a `next_reminder_at` to up to $limit learners who have a reminder time but
     * no scheduled instant yet. Conditional UPDATE (`WHERE next_reminder_at IS NULL`), so
     * two runs cannot both "backfill" a row that a parallel settings change just filled.
     */
    public function backfillMissing(?CarbonImmutable $now = null, ?int $limit = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $limit ??= $this->backfillLimit();
        $filled = 0;

        /** @var Collection<int, Learner> $learners */
        $learners = $this->reminderable()
            ->whereNotNull('reminder_time')
            ->whereNull('next_reminder_at')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($learners as $learner) {
            $next = $this->nextOccurrence($learner, $now);
            if ($next === null) {
                continue;
            }

            $filled += Learner::query()
                ->whereKey($learner->id)
                ->whereNull('next_reminder_at')
                ->update(['next_reminder_at' => $next->format('Y-m-d H:i:s')]);
        }

        return $filled;
    }

    /**
     * Not banned and DM not known to be blocked; soft-deleted rows are excluded by the
     * model's global scope. `reminder_time` is deliberately not filtered here: a row with
     * reminders switched off but a leftover `next_reminder_at` is claimed once and repaired
     * (pointer set to NULL) so it leaves the partial index.
     *
     * @return Builder<Learner>
     */
    private function reminderable(): Builder
    {
        return Learner::query()
            ->whereNull('dm_blocked_at')
            ->whereNull('blocked_at');
    }

    private function claim(Learner $learner, CarbonImmutable $now, ReminderDispatchReport $report): void
    {
        $seen = $learner->getRawOriginal('next_reminder_at');
        if (! is_string($seen) || $seen === '') {
            return;
        }

        $next = $this->nextOccurrence($learner, $now);

        $affected = Learner::query()
            ->whereKey($learner->id)
            ->where('next_reminder_at', $seen)
            ->update(['next_reminder_at' => $next?->format('Y-m-d H:i:s')]);

        if ($affected !== 1) {
            // Another dispatcher instance (or a settings change) moved it first.
            return;
        }

        if ($next === null) {
            $report->repaired++;

            return;
        }

        $report->claimed++;

        /** @var CarbonImmutable $scheduledFor */
        $scheduledFor = CarbonImmutable::parse($seen, 'UTC');

        if ($scheduledFor->addMinutes($this->maxDelayMinutes())->isBefore($now)) {
            $report->skippedStale++;

            return;
        }

        SendLearnerReminderJob::dispatch($learner->id, $scheduledFor)
            ->onQueue((string) config('telegram.queue', 'high'));

        $report->queued++;
    }

    /** `null` when reminders are off for this learner or the stored time is unusable. */
    private function nextOccurrence(Learner $learner, CarbonImmutable $now): ?CarbonImmutable
    {
        $time = $learner->reminder_time;
        if ($time === null || $time === '') {
            return null;
        }

        try {
            return $this->clock->nextOccurrenceUtc($learner, $time, $now);
        } catch (InvalidArgumentException $e) {
            Log::warning('reminders.invalid_reminder_time', ['learner_id' => $learner->id, 'reason' => $e->getMessage()]);

            return null;
        }
    }

    private function batchSize(): int
    {
        return max(1, (int) config('learning.reminders.batch_size', 200));
    }

    private function backfillLimit(): int
    {
        return max(1, (int) config('learning.reminders.backfill_limit', 1000));
    }

    private function maxDelayMinutes(): int
    {
        return max(0, (int) config('learning.reminders.max_delay_minutes', 120));
    }
}
