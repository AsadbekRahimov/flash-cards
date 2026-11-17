<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\LevelQuizResult;
use Illuminate\Http\Request;

/**
 * GET /api/twa/exam/sessions/{id}/result for a `level_quiz` session (docs/04 A.20); `mode` tells the client which shape it got.
 *
 * @property LevelQuizResult $resource
 */
final class LevelQuizResultResource extends TwaResource
{
    /**
     * @return array{
     *   mode: string,
     *   correct: int,
     *   total: int,
     *   answered: int,
     *   percent: int,
     *   scored: bool,
     *   week_start: string,
     *   course_id: int|null,
     *   course_slug: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $result = $this->resource;

        return [
            'mode' => $result->session->mode,
            'correct' => $result->correct,
            'total' => $result->total,
            'answered' => $result->answered,
            'percent' => $result->percent,
            'scored' => $result->scored,
            'week_start' => $result->weekStart,
            'course_id' => $result->session->course_id,
            'course_slug' => $result->session->course?->slug,
        ];
    }
}
