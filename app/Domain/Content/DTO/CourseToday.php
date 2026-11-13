<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** The learner's active course for the "Today" screen: the course, their progress and the lesson to take new words from (null once all were served). */
final readonly class CourseToday
{
    public function __construct(
        public CatalogCourse $course,
        public LearnerProgress $progress,
        public ?NextLesson $nextLesson,
    ) {}
}
