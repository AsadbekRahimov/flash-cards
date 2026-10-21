<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;

/**
 * Titles of stages / lessons as the learner reads them. A learner's own deck (`owner_type = learner`)
 * keeps technical Russian titles in the DB (LearnerDeckService), so they are replaced with the
 * learner's locale (`lang/{locale}/decks.php`); every other course keeps its own title.
 */
final class DeckTitles
{
    public function __construct(private readonly LocaleResolver $locales) {}

    public function stage(Course $course, Learner $learner, string $original): string
    {
        $locale = $this->localeFor($course, $learner);
        if ($locale === null) {
            return $original;
        }

        $title = __('decks.stage', [], $locale);

        return is_string($title) ? $title : $original;
    }

    public function lesson(Course $course, Learner $learner, int $number, ?string $original): ?string
    {
        $locale = $this->localeFor($course, $learner);
        if ($locale === null) {
            return $original;
        }

        $title = __('decks.part', ['n' => $number], $locale);

        return is_string($title) ? $title : $original;
    }

    /** For a lesson with its `stage.course` (eager-load them to avoid N+1). */
    public function forLesson(Lesson $lesson, Learner $learner): ?string
    {
        $course = $lesson->stage?->course;

        return $course === null ? $lesson->title : $this->lesson($course, $learner, $lesson->number, $lesson->title);
    }

    private function localeFor(Course $course, Learner $learner): ?string
    {
        return $course->isLearnerOwned() ? $this->locales->forLearner($learner) : null;
    }
}
