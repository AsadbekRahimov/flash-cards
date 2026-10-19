<?php

declare(strict_types=1);

namespace App\Domain\Learners\DTO;

/**
 * The learner's active course on the "Today" screen, localized for the learner. `learned` / `total`
 * count active words (as everywhere in the catalog); `nextLesson` is null once every word was served.
 */
final readonly class TodayCourse
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public int $learned,
        public int $total,
        public ?TodayLesson $nextLesson,
    ) {}
}
