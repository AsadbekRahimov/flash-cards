<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learning\DTO\SessionSummary;
use Illuminate\Http\Request;

/**
 * POST /api/twa/training/sessions/{id}/finish: what the learner did in the session plus the goal
 * and streak of the current local day (TodayService::progress).
 *
 * @property array{summary: SessionSummary, progress: DailyProgress} $resource
 */
final class SessionSummaryResource extends TwaResource
{
    /**
     * @return array{
     *   reviewed: int, correct: int, new_words: int,
     *   goal: array{target: int, done: int, met: bool},
     *   streak: array{current: int, best: int, met_today: bool, at_risk: bool, freezes: int, frozen_yesterday: bool}
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
        ];
    }
}
