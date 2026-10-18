<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\ExamSession;

final readonly class ExamJoin
{
    public function __construct(
        public ExamSession $session,
        public int $totalQuestions,
        public int $secondsPerQuestion,
        public ?ServedExamQuestion $question,
    ) {}
}
