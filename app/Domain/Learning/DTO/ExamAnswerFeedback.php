<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

final readonly class ExamAnswerFeedback
{
    public function __construct(
        public bool $isCorrect,
        public int $correctIndex,
        public int $scoreEarned,
        public int $totalScore,
        public bool $hasNext,
    ) {}
}
