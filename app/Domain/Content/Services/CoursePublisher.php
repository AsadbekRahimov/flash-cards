<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Exceptions\CoursePublishException;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Word;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Course lifecycle (docs/12 §1.7, §3.2): draft → review → published → archived.
 *
 * Allowed moves:
 *   draft     → review
 *   review    → draft (sent back), published
 *   published → archived
 *   archived  → draft (re-open for editing; it has to go through review again)
 *
 * Publishing is the only guarded move: the course needs a title in every
 * supported language, at least one lesson and at least one active word, and
 * a translation in EVERY supported locale (`uz`+`ru`) for at least
 * `config('content.publish_translation_threshold')` of its active words.
 * The gate is applied to NEW publications only — a course that is already
 * published is never re-checked, and the learner sees the ru fallback where
 * uz is missing (WordTranslationResolver).
 */
final class CoursePublisher
{
    public const DRAFT = 'draft';

    public const REVIEW = 'review';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::DRAFT => [self::REVIEW],
        self::REVIEW => [self::DRAFT, self::PUBLISHED],
        self::PUBLISHED => [self::ARCHIVED],
        self::ARCHIVED => [self::DRAFT],
    ];

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * @return list<string> statuses $course can move to from where it is now
     */
    public function allowedTransitions(Course $course): array
    {
        return self::TRANSITIONS[$course->status] ?? [];
    }

    /**
     * @throws CoursePublishException
     */
    public function transition(Course $course, string $to, User $by, ?string $ip = null): Course
    {
        if (! $by->is_active || ! $by->hasRole('admin')) {
            throw CoursePublishException::forbidden();
        }

        if (! array_key_exists($to, self::TRANSITIONS)) {
            throw CoursePublishException::unknownStatus($to);
        }

        return DB::transaction(function () use ($course, $to, $by, $ip): Course {
            // Re-read under a row lock: two admins pressing buttons at once must not both pass the gate.
            /** @var Course $locked */
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw CoursePublishException::invalidTransition($from, $to);
            }

            $report = null;
            if ($to === self::PUBLISHED) {
                $report = $this->assertPublishable($locked);
            }

            $locked->status = $to;
            if ($to === self::PUBLISHED) {
                $locked->published_at = now();
            }
            $locked->save();

            AuditLog::create([
                'user_id' => $by->id,
                'action' => 'course.status_changed',
                'entity_type' => Course::class,
                'entity_id' => $locked->id,
                'ip' => $ip,
                'meta' => array_filter([
                    'slug' => $locked->slug,
                    'from' => $from,
                    'to' => $to,
                    'translations' => $report,
                ], fn ($v): bool => $v !== null),
            ]);

            return $course->refresh();
        });
    }

    /** Recomputes the denormalised `courses.words_count` (active words). */
    public function recount(Course $course): void
    {
        $count = $this->courseWords($course)->count();

        Course::query()->whereKey($course->id)->update(['words_count' => $count]);
        $course->words_count = $count;
        $course->syncOriginalAttribute('words_count');
    }

    /**
     * Share of active words that have a non-empty translation in every supported locale.
     *
     * @return array{total:int, covered:int, ratio:float, locales:list<string>}
     */
    public function translationCoverage(Course $course): array
    {
        $locales = $this->locales->supported();
        $total = $this->courseWords($course)->count();

        $covered = $this->courseWords($course);
        foreach ($locales as $locale) {
            $covered->whereHas('translations', fn (Builder $q) => $q
                ->where('locale', $locale)
                ->where('translation', '!=', ''));
        }
        $covered = $covered->count();

        return [
            'total' => $total,
            'covered' => $covered,
            'ratio' => $total > 0 ? $covered / $total : 0.0,
            'locales' => $locales,
        ];
    }

    /**
     * @return array{total:int, covered:int, ratio:float, locales:list<string>}
     *
     * @throws CoursePublishException
     */
    private function assertPublishable(Course $course): array
    {
        $missing = [];
        foreach ($this->locales->supported() as $locale) {
            $title = $course->title[$locale] ?? null;
            if (! is_string($title) || trim($title) === '') {
                $missing[] = $locale;
            }
        }
        if ($missing !== []) {
            throw CoursePublishException::titleIncomplete($missing);
        }

        $hasLessons = Lesson::query()
            ->whereHas('stage', fn (Builder $q) => $q->where('course_id', $course->id))
            ->exists();
        if (! $hasLessons) {
            throw CoursePublishException::noLessons();
        }

        $coverage = $this->translationCoverage($course);
        if ($coverage['total'] === 0) {
            throw CoursePublishException::noWords();
        }

        $threshold = max(0.0, min(1.0, (float) config('content.publish_translation_threshold', 0.95)));
        // Compare counts, not floats: 19 of 20 words is exactly 95%.
        if ($coverage['covered'] < (int) ceil($coverage['total'] * $threshold - 1e-9)) {
            throw CoursePublishException::translationsBelowThreshold(
                $coverage['covered'],
                $coverage['total'],
                $threshold,
                $coverage['locales'],
            );
        }

        return $coverage;
    }

    /**
     * @return Builder<Word>
     */
    private function courseWords(Course $course): Builder
    {
        return Word::query()
            ->where('is_active', true)
            ->whereHas('lesson.stage', fn (Builder $q) => $q->where('course_id', $course->id));
    }
}
