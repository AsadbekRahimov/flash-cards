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
        private readonly WordAudioService $audio,
    ) {}

    public function wordChanged(Word $word): void
    {
        $lesson = $word->lesson()->with('stage.course')->first();
        if ($lesson === null) {
            return;
        }

        $this->cache->forgetLesson($lesson);

        $course = $lesson->stage?->course;
        if ($course !== null) {
            $this->publisher->recount($course);
        }
    }

    /** The English text of a word was edited in the admin panel: its pre-generated audio is stale. */
    public function wordTextChanged(Word $word): void
    {
        $this->audio->textChanged($word);
    }
}
