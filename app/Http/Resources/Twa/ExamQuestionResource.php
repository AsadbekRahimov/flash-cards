<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Learning\DTO\ServedExamQuestion;
use Illuminate\Http\Request;

/**
 * GET /api/twa/exam/sessions/{id}/question
 *
 * Student-facing allow-list: the personalizer view also holds `correct_index`,
 * `correct_translation` and `canonical_index`, which must never leave the server
 * while the question is open.
 *
 * @property ServedExamQuestion $resource
 */
final class ExamQuestionResource extends TwaResource
{
    /**
     * @return array{question_index: int, word_id: int, word: string, options: list<string>, seconds_left: int}
     */
    public function toArray(Request $request): array
    {
        $question = $this->resource->question;

        return [
            'question_index' => $question['question_index'],
            'word_id' => $question['word_id'],
            'word' => $question['word'],
            'options' => $question['options'],
            'seconds_left' => $this->resource->secondsLeft,
        ];
    }
}
