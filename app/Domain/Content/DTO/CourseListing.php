<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/** One catalog row for a learner: the course, their progress and whether it is their active course. */
final readonly class CourseListing
{
    public function __construct(
        public CatalogCourse $course,
        public LearnerProgress $progress,
        public bool $isActive,
    ) {}
}
