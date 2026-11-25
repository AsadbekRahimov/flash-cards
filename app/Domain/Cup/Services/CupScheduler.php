<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `cups:advance` (docs/33 §3.2): every transition that the clock makes, each idempotent and in batches of `cup.advance_batch`:
 *
 * 1. drafts unpublished for `draft_ttl_hours` → canceled (`draft_expired`);
 * 2. scheduled cups at their midnight → running (two players), or the start moves a day, or canceled (`no_players`);
 * 3. the snapshots of the days that begin within the hour;
 * 4. attempts left open past their deadline → `expired`;
 * 5. running cups `settle_minutes` after their end → finished, and the row `final` the finish wrote is queued for the bot at once
 *    ({@see CupOutbox::queue()}; the finish itself knows nothing of the bot, `cups:send-outbox` stays the safety net). With the duels on,
 *    the duels of the cup whose battle is over are scored first ({@see CupDuelService::settleCup()}, docs/33 §5.6).
 *
 * Each cup is its own transaction under the lock of its row ({@see CupLifecycleService}); the candidates are read through the partial
 * indexes of the open rows only, so the cost does not grow with the history. A failure of one cup is reported and the batch goes on.
 * Two schedulers at once (or a manual run next to the scheduled one) are safe: each transition re-checks under the lock.
 */
final class CupScheduler
{
    public function __construct(
        private readonly CupLifecycleService $lifecycle,
        private readonly CupRoundService $rounds,
        private readonly CupOutbox $outbox,
        private readonly CupDuelService $duels,
    ) {}

    /** @return array{drafts_expired: int, started: int, shifted: int, canceled: int, snapshots: int, attempts_expired: int, finished: int, failed: int} */
    public function advance(CarbonImmutable $now, ?int $batch = null): array
    {
        $batch ??= max(1, (int) config('cup.advance_batch', 200));
        $report = ['drafts_expired' => 0, 'started' => 0, 'shifted' => 0, 'canceled' => 0, 'snapshots' => 0, 'attempts_expired' => 0, 'finished' => 0, 'failed' => 0];

        $draftDeadline = $now->subHours(max(1, (int) config('cup.draft_ttl_hours', 48)));
        foreach ($this->ids(CupStatus::Draft, 'created_at', $draftDeadline, $batch) as $id) {
            $this->guard($report, $id, function () use ($id, $now, &$report): void {
                $report['drafts_expired'] += (int) $this->lifecycle->expireDraft($id, $now);
            });
        }

        foreach ($this->ids(CupStatus::Scheduled, 'starts_at', $now, $batch) as $id) {
            $this->guard($report, $id, function () use ($id, $now, &$report): void {
                $outcome = $this->lifecycle->start($id, $now);
                if ($outcome !== 'noop') {
                    $report[$outcome]++;
                }
            });
        }

        try {
            $report['snapshots'] = $this->rounds->buildUpcoming($now, $batch);
        } catch (Throwable $e) {
            report($e);
            $report['failed']++;
        }

        try {
            $report['attempts_expired'] = $this->rounds->closeExpired($now, $batch);
        } catch (Throwable $e) {
            report($e);
            $report['failed']++;
        }

        $finishBefore = $now->subMinutes(max(0, (int) config('cup.settle_minutes', 30)));
        foreach ($this->ids(CupStatus::Running, 'ends_at', $finishBefore, $batch) as $id) {
            $this->guard($report, $id, function () use ($id, $now, &$report): void {
                // The results of the duels whose battle is over go in BEFORE the places (docs/33 §5.6); a later one changes nothing.
                if ((bool) config('cup.duels', false)) {
                    $this->duels->settleCup($id, $now);
                }
                if ($this->lifecycle->finish($id, $now)) {
                    $report['finished']++;
                    $this->outbox->queue($id, CupOutbox::FINAL_KEY);
                }
            });
        }

        return [
            'drafts_expired' => $report['drafts_expired'],
            'started' => $report['started'],
            'shifted' => $report['shifted'],
            'canceled' => $report['canceled'],
            'snapshots' => $report['snapshots'],
            'attempts_expired' => $report['attempts_expired'],
            'finished' => $report['finished'],
            'failed' => $report['failed'],
        ];
    }

    /**
     * The ids of the cups in `$status` whose `$column` is due, through the partial index of that status.
     *
     * @return list<int>
     */
    private function ids(CupStatus $status, string $column, CarbonImmutable $due, int $batch): array
    {
        return array_values(DB::table('cups')
            ->where('status', $status->value)
            ->where($column, '<=', $due)
            ->orderBy($column)
            ->limit($batch)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  array<string, int>  $report
     * @param  callable(): void  $step
     */
    private function guard(array &$report, int $cupId, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            $report['failed']++;
            Log::warning('cup.advance_failed', ['cup_id' => $cupId, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            report($e);
        }
    }
}
