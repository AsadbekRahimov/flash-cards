<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ExamAnswerFeedback;
use Illuminate\Http\Request;

/**
 * POST /api/twa/exam/sessions/{id}/answer
 *
 * `correct_option` is an index into THIS learner's `options`; it is revealed
 * only after the answer is recorded (the TWA highlights it).
 *
 * @property ExamAnswerFeedback $resource
 */
final class ExamAnswerResource extends TwaResource
{
    /**
     * @return array{
     *   is_correct: bool,
     *   correct_option: int,
     *   score_earned: int,
     *   total_score: int,
     *   has_next: bool,
     *   next_question: null
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'is_correct' => $this->resource->isCorrect,
            'correct_option' => $this->resource->correctIndex,
            'score_earned' => $this->resource->scoreEarned,
            'total_score' => $this->resource->totalScore,
            'has_next' => $this->resource->hasNext,
            // The next question is fetched after feedback so its server timer starts at display time.
            'next_question' => null,
        ];
    }
}
