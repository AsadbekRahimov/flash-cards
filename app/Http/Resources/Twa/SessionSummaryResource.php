<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learning\DTO\SessionSummary;
use App\Domain\Practice\DTO\PracticeEntry;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/finish: what the learner did in the session plus the goal
 * and streak of the current local day (TodayService::progress) and the entry to the practice of the lesson / of the
 * course (`practice_entry`, docs/16 §11.1: `null` when there is none).
 *
 * @property array{summary: SessionSummary, progress: DailyProgress, practice_entry: PracticeEntry|null} $resource
 */
final class SessionSummaryResource extends TwaResource
{
    /**
     * @return array{
     *   reviewed: int, correct: int, new_words: int,
     *   goal: array{target: int, done: int, met: bool},
     *   streak: array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool},
     *   practice_entry: array{course: string, lesson_id: int|null}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource['summary'];
        $progress = $this->resource['progress'];

        return [
            'reviewed' => $summary->reviewed,
            'correct' => $summary->correct,
            'new_words' => $summary->newWords,
            'goal' => $progress->goal->toArray(),
            'streak' => $progress->streak->toArray(),
            'practice_entry' => $this->resource['practice_entry']?->toArray(),
        ];
    }
}
