<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ExamJoin;
use Illuminate\Http\Request;

/**
 * POST /api/twa/exam/sessions/{id}/join
 *
 * @property ExamJoin $resource
 */
final class ExamJoinResource extends TwaResource
{
    /**
     * @return array{
     *   session_id: int,
     *   ends_at: string|null,
     *   total_questions: int,
     *   seconds_per_question: int,
     *   question: array{question_index: int, word_id: int, word: string, options: list<string>, seconds_left: int}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $first = $this->resource->question;

        return [
            'session_id' => $this->resource->session->id,
            'ends_at' => $this->resource->session->ends_at?->toIso8601String(),
            'total_questions' => $this->resource->totalQuestions,
            'seconds_per_question' => $this->resource->secondsPerQuestion,
            'question' => $first !== null ? (new ExamQuestionResource($first))->toArray($request) : null,
        ];
    }
}
