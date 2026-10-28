<?php

declare(strict_types=1);

namespace App\Domain\Content\DTO;

/**
 * Outcome of the repair run `courses:recount-words` (LessonContentRefresher::refreshAll): how many courses were
 * checked, which ones had a `words_count` that differed from their active words (and were corrected), and for how
 * many lessons the cached word count was dropped.
 */
final readonly class CourseRecountReport
{
    /** @param  list<array{id:int, slug:string, from:int, to:int}>  $changes  the corrected courses, by id */
    public function __construct(
        public int $courses,
        public array $changes,
        public int $lessons,
    ) {}

    public function changed(): int
    {
        return count($this->changes);
    }
}
