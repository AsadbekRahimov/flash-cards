<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** Course page for a learner: the course, overall progress and lessons (grouped into stages by the Resource). */
final readonly class CourseDetail
{
    /**
     * @param  list<LessonProgress>  $lessons  in study order (stage.number, lesson.number)
     */
    public function __construct(
        public CatalogCourse $course,
        public LearnerProgress $progress,
        public bool $isActive,
        public array $lessons,
    ) {}
}
