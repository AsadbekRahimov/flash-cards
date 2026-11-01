<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Practice\Enums\Verdict;

/**
 * What {@see SentenceGrader::grade()} decided about one answer: the verdict, the order of the fragment ids as it was
 * accepted (empty for "I don't know") and the text the learner put together (null for "I don't know"), as the fragments
 * are written - not normalized, not capitalized.
 */
final readonly class SentenceGrade
{
    /** @param list<string> $order */
    public function __construct(
        public Verdict $verdict,
        public array $order,
        public ?string $text,
    ) {}
}
