<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\Lesson;
use App\Models\TrainingSession;

final readonly class TrainingStart
{
    public function __construct(
        public TrainingSession $session,
        public Lesson $lesson,
        public int $totalWords,
        public TrainingStep $step,
    ) {}
}
