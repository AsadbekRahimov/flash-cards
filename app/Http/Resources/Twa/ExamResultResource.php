<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ExamLearnerResult;
use Illuminate\Http\Request;

/**
 * GET /api/twa/exam/sessions/{id}/result
 *
 * @property ExamLearnerResult $resource
 */
final class ExamResultResource extends TwaResource
{
    /**
     * @return array{
     *   learner_score: int,
     *   rank: int|null,
     *   total_participants: int,
     *   leaderboard: list<array{rank: int, name: string, score: int}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'learner_score' => $this->resource->score,
            'rank' => $this->resource->rank,
            'total_participants' => $this->resource->totalParticipants,
            'leaderboard' => $this->resource->top,
        ];
    }
}
