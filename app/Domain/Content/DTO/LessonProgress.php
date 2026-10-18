<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** A lesson of a course in study order, with its stage and the learner's progress in it. */
final readonly class LessonProgress
{
    public function __construct(
        public int $stageNumber,
        public string $stageTitle,
        public int $lessonId,
        public int $lessonNumber,
        public ?string $lessonTitle,
        public LearnerProgress $progress,
    ) {}
}
