<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\ExamSession;

/**
 * Outcome of a quiz of the week (docs/13 §8.5). `percent` is the floor of the correct answers over ALL questions
 * (an unanswered question is wrong). `scored` - this was the learner's counted quiz of the week; false for a practice
 * attempt and for a quiz closed without a single answer (nothing is recorded for it).
 */
final readonly class LevelQuizResult
{
    public function __construct(
        public ExamSession $session,
        public int $correct,
        public int $total,
        public int $answered,
        public int $percent,
        public bool $scored,
        public string $weekStart,
    ) {}
}
