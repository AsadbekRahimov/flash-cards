<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\MockExamResult;
use Illuminate\Http\Request;

/**
 * GET /api/twa/exam/sessions/{id}/result for a `personal_mock` session (docs/04 A.4a).
 * The group-exam shape (`ExamResultResource`) is untouched; `mode` tells the client which one it got.
 *
 * @property MockExamResult $resource
 */
final class MockExamResultResource extends TwaResource
{
    /**
     * @return array{
     *   mode: string,
     *   correct: int,
     *   total: int,
     *   answered: int,
     *   percent: int,
     *   percentile: int|null,
     *   attempts: int,
     *   course_id: int|null
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
            'percentile' => $result->percentile,
            'attempts' => $result->attempts,
            'course_id' => $result->session->course_id,
        ];
    }
}
