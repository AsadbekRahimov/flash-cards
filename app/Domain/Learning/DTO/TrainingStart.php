<?php

declare(strict_types=1);

namespace App\Domain\Learning\DTO;

use App\Models\Lesson;
use App\Models\TrainingSession;

final readonly class TrainingStart
{
    /**
     * @param  Lesson|null  $lesson  null for `daily_review` (the scope spans every lesson)
     * @param  int  $totalWords  lesson scope: active words of the lesson; daily scope: the learner's daily_goal
     */
    public function __construct(
        public TrainingSession $session,
        public ?Lesson $lesson,
        public int $totalWords,
        public TrainingStep $step,
    ) {}
}
