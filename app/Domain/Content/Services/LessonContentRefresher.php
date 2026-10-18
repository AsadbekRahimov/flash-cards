<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Learning\Services\LearningCache;
use App\Models\Word;

/**
 * Keeps derived data in step after a word was added or its `is_active` flag moved in the admin
 * panel: the cached lesson word count and the course's denormalised `words_count`.
 * (The importer does the same on its own in its bulk path.)
 */
final class LessonContentRefresher
{
    public function __construct(
        private readonly LearningCache $cache,
        private readonly CoursePublisher $publisher,
    ) {}

    public function wordChanged(Word $word): void
    {
        $lesson = $word->lesson()->with('stage.course')->first();
        if ($lesson === null) {
            return;
        }

        $this->cache->forgetLesson($lesson);
        $this->publisher->recount($lesson->stage->course);
    }
}
