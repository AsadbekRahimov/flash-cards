<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Support\IsoTime;
use App\Models\PracticeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The personal best of a learner per mode (docs/16 §2.10): `practice_records`, one row per `(learner_id, mode)`.
 *
 * It is written by the transaction that FINISHES a session (`completed` / `time_over`, never `expired` / `replaced`), AFTER the row of the
 * session is locked: the order of the locks is `practice_sessions` -> `practice_records` (docs/16 §9 rule 24) - nothing takes a record
 * first. A score that is not better than the best (or is 0) changes nothing.
 */
final class PracticeRecords
{
    /**
     * Records `$score` of a finished session when it beats the best; true then ("a new record"). Call it inside the transaction that holds
     * the session's lock.
     */
    public function record(int $learnerId, PracticeMode $mode, int $score, int $sessionId, CarbonImmutable $now): bool
    {
        if ($score < 1) {
            return false;
        }

        $row = $this->lock($learnerId, $mode);
        if ($row === null) {
            try {
                // A savepoint: a unique violation of a parallel insert must not abort the outer transaction.
                DB::transaction(fn () => PracticeRecord::query()->create([
                    'learner_id' => $learnerId,
                    'mode' => $mode,
                    'best_score' => $score,
                    'best_session_id' => $sessionId,
                    'achieved_at' => $now,
                ]));

                return true;
            } catch (UniqueConstraintViolationException) {
                $row = $this->lock($learnerId, $mode);
            }
        }

        if ($row === null || $score <= $row->best_score) {
            return false;
        }

        $row->forceFill(['best_score' => $score, 'best_session_id' => $sessionId, 'achieved_at' => $now])->save();

        return true;
    }

    /** The best of the learner in a mode, or null. One statement. */
    public function best(int $learnerId, PracticeMode $mode): ?PracticeRecord
    {
        /** @var PracticeRecord|null $row */
        $row = PracticeRecord::query()->where('learner_id', $learnerId)->where('mode', $mode->value)->first();

        return $row;
    }

    /**
     * Every record of the learner, for the hub: one statement.
     *
     * @return array<string, array{score: int, achieved_at: string}> mode value => the record
     */
    public function forLearner(int $learnerId): array
    {
        $records = [];
        /** @var Collection<int, PracticeRecord> $rows */
        $rows = PracticeRecord::query()->where('learner_id', $learnerId)->get();
        foreach ($rows as $row) {
            $records[$row->mode->value] = ['score' => $row->best_score, 'achieved_at' => IsoTime::format($row->achieved_at)];
        }

        return $records;
    }

    private function lock(int $learnerId, PracticeMode $mode): ?PracticeRecord
    {
        /** @var PracticeRecord|null $row */
        $row = PracticeRecord::query()->where('learner_id', $learnerId)->where('mode', $mode->value)->lockForUpdate()->first();

        return $row;
    }
}
