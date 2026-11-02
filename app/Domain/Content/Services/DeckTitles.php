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

    /**
     * A module without a name of its own (`$original` null) stays null: the client names it by its number in the
     * reader's language ("Модуль 3" / "3-modul", docs/14 §8). A learner's deck always has its own caption.
     */
    public function stage(Course $course, Learner $learner, ?string $original): ?string
    {
        return $course->isLearnerOwned() ? $this->stageIn($course, $this->locales->forLearner($learner), $original) : $original;
    }

    public function lesson(Course $course, Learner $learner, int $number, ?string $original): ?string
    {
        return $course->isLearnerOwned() ? $this->lessonIn($course, $this->locales->forLearner($learner), $number, $original) : $original;
    }

    /** The module title in a locale: what {@see stage()} gives a learner of that locale (the cached course tree is per locale). */
    public function stageIn(Course $course, string $locale, ?string $original): ?string
    {
        if (! $course->isLearnerOwned()) {
            return $original;
        }

        $title = __('decks.stage', [], $locale);

        return is_string($title) ? $title : $original;
    }

    /** The lesson title in a locale: what {@see Lesson()} gives a learner of that locale. */
    public function lessonIn(Course $course, string $locale, int $number, ?string $original): ?string
    {
        if (! $course->isLearnerOwned()) {
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
}
