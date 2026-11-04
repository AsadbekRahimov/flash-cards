<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;

/**
 * Titles of stages / lessons as the learner reads them - THE place that picks a name by locale (docs/03 §2.5-§2.6):
 *
 *   - an ordinary course keeps two names per module / lesson, `title` (ru) and `title_uz`: a reader of `uz` is given
 *     `title_uz` when it is set, else `title`; a reader of the fallback locale (`ru`) reads `title`. Blank is "no name";
 *   - a learner's own deck (`owner_type = learner`) keeps technical Russian titles in the DB (LearnerDeckService), so they
 *     are replaced with the learner's locale (`lang/{locale}/decks.php`).
 *
 * Null means "no name of its own": the client names the module by its number in the reader's language ("Модуль 3" /
 * "3-modul", docs/14 §8).
 */
final class DeckTitles
{
    /** The locale whose names live in a column of their own beside `title` (the column is `title_{locale}`). */
    private const OWN_COLUMN_LOCALE = 'uz';

    public function __construct(private readonly LocaleResolver $locales) {}

    /** The module title a learner reads (needs `title` and `title_uz` on the model). */
    public function stage(Course $course, Learner $learner, Stage $stage): ?string
    {
        return $this->stageIn($course, $this->locales->forLearner($learner), $stage->title, $stage->title_uz);
    }

    /** The lesson title a learner reads (needs `title` and `title_uz` on the model). */
    public function lesson(Course $course, Learner $learner, Lesson $lesson): ?string
    {
        return $this->lessonIn($course, $this->locales->forLearner($learner), $lesson->number, $lesson->title, $lesson->title_uz);
    }

    /** The module title in a locale: what stage() gives a learner of that locale (the cached course tree is per locale). */
    public function stageIn(Course $course, string $locale, ?string $title, ?string $titleUz): ?string
    {
        if (! $course->isLearnerOwned()) {
            return $this->pick($locale, $title, $titleUz);
        }

        $caption = __('decks.stage', [], $locale);

        return is_string($caption) ? $caption : $this->pick($locale, $title, $titleUz);
    }

    /** The lesson title in a locale: what lesson() gives a learner of that locale. */
    public function lessonIn(Course $course, string $locale, int $number, ?string $title, ?string $titleUz): ?string
    {
        if (! $course->isLearnerOwned()) {
            return $this->pick($locale, $title, $titleUz);
        }

        $caption = __('decks.part', ['n' => $number], $locale);

        return is_string($caption) ? $caption : $this->pick($locale, $title, $titleUz);
    }

    /**
     * The lesson title in each of `$locales` - for a snapshot read later by readers of different locales (a battle of two
     * learners). A locale without a name has no entry.
     *
     * @param  iterable<string>  $locales
     * @return array<string, string> locale => title
     */
    public function lessonTitles(Course $course, Lesson $lesson, iterable $locales): array
    {
        $titles = [];
        foreach ($locales as $locale) {
            $title = $this->lessonIn($course, $locale, $lesson->number, $lesson->title, $lesson->title_uz);
            if ($title !== null) {
                $titles[$locale] = $title;
            }
        }

        return $titles;
    }

    /** For a lesson with its `stage.course` (eager-load them to avoid N+1). */
    public function forLesson(Lesson $lesson, Learner $learner): ?string
    {
        $course = $lesson->stage?->course;

        return $course === null
            ? $this->pick($this->locales->forLearner($learner), $lesson->title, $lesson->title_uz)
            : $this->lesson($course, $learner, $lesson);
    }

    /** `title_uz` for a reader of uz when it is set, else `title`; blank is no name. */
    private function pick(string $locale, ?string $title, ?string $titleUz): ?string
    {
        if ($locale === self::OWN_COLUMN_LOCALE && $titleUz !== null && trim($titleUz) !== '') {
            return $titleUz;
        }

        return $title !== null && trim($title) !== '' ? $title : null;
    }
}
