<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/** The lesson `daily_review` takes new words from next (CourseCatalogService::nextLessonWithNewWords). */
final readonly class TodayLesson
{
    public function __construct(
        public int $id,
        public ?int $stage,
        public int $lesson,
        public ?string $title,
    ) {}
}
