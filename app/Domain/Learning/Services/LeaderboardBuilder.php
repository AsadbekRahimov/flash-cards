<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates `exam_answers` → `exam_results` for a closed exam.
 *
 * Ranking rules:
 *   1. Higher total_score first.
 *   2. On tie — more correct answers wins.
 *   3. On further tie — lower total time spent wins.
 *   4. Remaining ties share the same rank (competition ranking: 1, 2, 2, 4).
 *
 * Idempotent: wipes any pre-existing results for the session and rebuilds.
 */
final class LeaderboardBuilder
{
    /**
     * @return Collection<int, ExamResult>
     */
    public function build(ExamSession $session): Collection
    {
        return DB::transaction(function () use ($session): Collection {
            ExamResult::query()->where('exam_session_id', $session->id)->delete();

            /** @var Collection<int, object> $rows */
            $rows = ExamAnswer::query()
                ->where('exam_session_id', $session->id)
                ->whereNotNull('student_id')
                ->selectRaw(
                    'student_id,
                     SUM(score) AS total_score,
                     SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) AS correct_count,
                     COUNT(*) AS total_count,
                     SUM(time_spent_ms) AS time_spent_ms',
                )
                ->groupBy('student_id')
                ->get();

            $sorted = $rows
                ->map(fn ($r): array => [
                    'student_id' => (int) $r->student_id,
                    'total_score' => (int) $r->total_score,
                    'correct_count' => (int) $r->correct_count,
                    'total_count' => (int) $r->total_count,
                    'time_spent_ms' => (int) $r->time_spent_ms,
                ])
                ->sortBy([
                    ['total_score', 'desc'],
                    ['correct_count', 'desc'],
                    ['time_spent_ms', 'asc'],
                ])
                ->values();

            return $this->assignRanks($session, $sorted);
        });
    }

    /**
     * @param  Collection<int, array{student_id:int, total_score:int, correct_count:int, total_count:int, time_spent_ms:int}>  $rows
     * @return Collection<int, ExamResult>
     */
    private function assignRanks(ExamSession $session, Collection $rows): Collection
    {
        $results = collect();
        $lastKey = null;
        $lastRank = 0;

        foreach ($rows as $index => $row) {
            $tieKey = "{$row['total_score']}|{$row['correct_count']}|{$row['time_spent_ms']}";
            if ($tieKey === $lastKey) {
                $rank = $lastRank;
            } else {
                $rank = $index + 1;
                $lastRank = $rank;
                $lastKey = $tieKey;
            }

            $result = ExamResult::query()->create([
                'exam_session_id' => $session->id,
                'student_id' => $row['student_id'],
                'total_score' => $row['total_score'],
                'correct_count' => $row['correct_count'],
                'total_count' => $row['total_count'],
                'rank' => $rank,
            ]);

            $results->push($result);
        }

        return $results;
    }
}
