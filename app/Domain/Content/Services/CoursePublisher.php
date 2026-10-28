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
use App\Models\WordTranslation;
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
 * The `default` course (config `content.default_course_slug`) can never be archived.
 *
 * Publishing is the other guarded move: the course needs a title in every
 * supported language, at least one lesson and at least one active word, and
 * a translation in EVERY supported locale (`uz`+`ru`; for ru the legacy
 * `words.translation` counts too) for at least
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

    public function __construct(
        private readonly LocaleResolver $locales,
        private readonly CourseCatalogService $catalog,
    ) {}

    /**
     * @return list<string> statuses $course can move to from where it is now
     */
    public function allowedTransitions(Course $course): array
    {
        $to = self::TRANSITIONS[$course->status] ?? [];

        return $this->isDefault($course) ? array_values(array_diff($to, [self::ARCHIVED])) : $to;
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

        $result = DB::transaction(function () use ($course, $to, $by, $ip): Course {
            // Re-read under a row lock: two admins pressing buttons at once must not both pass the gate.
            /** @var Course $locked */
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw CoursePublishException::invalidTransition($from, $to);
            }

            $report = null;
            if ($to === self::ARCHIVED && $this->isDefault($locked)) {
                throw CoursePublishException::defaultCourseProtected();
            }

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

        // After commit (the caller's too, if it wraps us): a request that re-fills the cache earlier
        // would store the old status.
        DB::afterCommit(fn () => $this->catalog->forgetPublished());

        return $result;
    }

    /** The platform default course serves groups with no course of their own: it cannot go away. */
    private function isDefault(Course $course): bool
    {
        return $course->slug === (string) config('content.default_course_slug');
    }

    /**
     * Takes the row of the course until the end of the caller's transaction (call it inside one): the lock the
     * writers of ONE course take first, so they work one after another. Without it two imports into the same
     * course count the active words from snapshots that lack each other's uncommitted rows, and the later commit
     * leaves a {@see recount()} figure that misses the earlier import's words; a file submitted twice into an
     * existing lesson makes the second import insert the words the first one has not committed yet and fail on
     * the unique `(lesson_id, word)` instead of finding them and skipping.
     *
     * Taken the same way by the importer (VocabularyImporter), the repair run (LessonContentRefresher::refreshAll) and the
     * recount after a word edit of the admin panel (LessonContentRefresher::wordChanged); the decks (LearnerDeckService)
     * and {@see transition()} lock the row `FOR UPDATE` for the same purpose. It is the FIRST lock of each of these
     * transactions (what they read before takes none); the lessons and words they write afterwards are not held by anyone
     * who then waits for the course - the word edits of the admin panel run in autocommit, one statement at a time, and
     * only their recount takes the course row, in a transaction of its own after the word is committed (a panel with
     * `databaseTransactions()` would turn the two into word-then-course, the opposite order; CourseWriterLocksTest pins
     * it) - so no cycle can form.
     *
     * `FOR NO KEY UPDATE` on PostgreSQL, as in CardPicker: it conflicts with `FOR UPDATE`, with itself and with the
     * `UPDATE courses` of `recount()`, but not with the `FOR KEY SHARE` of a foreign-key check, so learners opening
     * a session of the course or choosing it as their active one are not held up for the length of an import (with
     * `FOR UPDATE` they would be). SQLite has no row locks and a single writer.
     *
     * @return Course|null the locked row as it is now; null when the course is gone
     */
    public function lockForWriting(Course $course): ?Course
    {
        $lock = Course::query()->whereKey($course->id);

        /** @var Course|null $locked */
        $locked = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->first();

        return $locked;
    }

    /** Active words of the course as they are now: THE definition behind the denormalised `courses.words_count`. */
    public function countActiveWords(Course $course): int
    {
        return $this->courseWords($course)->count();
    }

    /** Recomputes the denormalised `courses.words_count` (active words). */
    public function recount(Course $course): void
    {
        $count = $this->countActiveWords($course);

        Course::query()->whereKey($course->id)->update(['words_count' => $count]);
        $course->words_count = $count;
        $course->syncOriginalAttribute('words_count');

        // The importer calls this inside its transaction: forget once that commits.
        DB::afterCommit(fn () => $this->catalog->forgetPublished());
    }

    /**
     * Share of active words that have a non-empty translation in every supported locale.
     *
     * "Non-empty" is the {@see WordTranslationResolver} rule: the text is not blank after `trim()`.
     * For the fallback locale (ru) the legacy `words.translation` counts as well as a ru row — the
     * resolver reads it when the row is absent, so a word covered that way is a covered word.
     *
     * @return array{total:int, covered:int, ratio:float, locales:list<string>}
     */
    public function translationCoverage(Course $course): array
    {
        $locales = $this->locales->supported();
        $fallback = $this->locales->fallback();
        $total = 0;
        $covered = 0;

        $this->courseWords($course)->select('words.id', 'words.translation')->chunkById(500, function ($words) use ($locales, $fallback, &$total, &$covered): void {
            /** @var array<int, array<string, true>> $has word_id => locale => true */
            $has = [];
            WordTranslation::query()
                ->whereIn('word_id', $words->pluck('id')->all())
                ->whereIn('locale', $locales)
                ->get(['word_id', 'locale', 'translation'])
                ->each(function (WordTranslation $row) use (&$has): void {
                    if (trim($row->translation) !== '') {
                        $has[(int) $row->word_id][$row->locale] = true;
                    }
                });

            foreach ($words as $word) {
                $total++;
                $ok = true;
                foreach ($locales as $locale) {
                    $present = isset($has[(int) $word->id][$locale])
                        || ($locale === $fallback && trim((string) $word->translation) !== '');
                    if (! $present) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $covered++;
                }
            }
        }, 'words.id', 'id');

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
