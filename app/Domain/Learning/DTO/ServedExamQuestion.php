<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

/**
 * A question as served to ONE student. `$question` is the raw personalizer
 * view and still carries the answer key (`correct_index`, `correct_translation`,
 * `canonical_index`): it must never be serialised as is — the API resource
 * picks the allowed fields.
 */
final readonly class ServedExamQuestion
{
    /**
     * @param  array{
     *   question_index: int,
     *   canonical_index: int,
     *   word_id: int,
     *   word: string,
     *   options: list<string>,
     *   correct_index: int,
     *   correct_translation: string
     * }  $question
     */
    public function __construct(
        public array $question,
        public int $secondsLeft,
    ) {}
}
