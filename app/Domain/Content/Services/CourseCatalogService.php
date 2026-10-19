<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\CatalogCourse;
use App\Domain\Content\DTO\CourseDetail;
use App\Domain\Content\DTO\CourseListing;
use App\Domain\Content\DTO\LearnerProgress;
use App\Domain\Content\DTO\LessonProgress;
use App\Domain\Localization\Services\LocaleResolver;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Single place that knows which course content belongs to (docs/12 §1.7, §3.2):
 * the platform `default` course, the course of a group, and stage / lesson
 * lookup INSIDE a course — stage numbers are unique per course, not globally.
 *
 * Catalog for learners (task 1.2b): `published()` is the cached,
 * learner-independent list of public courses; `forLearner()` / `detail()`
 * add the learner's progress, which is never cached (one aggregate query
 * per page). A course is *available* (openable by slug, selectable as the
 * active course) when it is published and not private; the list shows only
 * `public` ones. Anything not available answers "not found", so draft /
 * private / archived courses are not revealed.
 */
final class CourseCatalogService
{
    private const PUBLISHED_CACHE_PREFIX = 'catalog:courses:published:v1:';

    private const PUBLISHED_CACHE_TTL = 600;

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * The platform course that receives demo content and imports without an
     * explicit course, and serves groups whose `course_id` is NULL. Created
     * on first use (race-safe).
     */
    public function defaultCourse(): Course
    {
        return Course::query()->createOrFirst(
            ['slug' => (string) config('content.default_course_slug')],
            [
                'owner_type' => 'platform',
                'title' => ['ru' => 'Базовый курс', 'uz' => 'Asosiy kurs'],
                'visibility' => 'public',
                'status' => 'published',
                'published_at' => now(),
            ],
        );
    }

    public function findBySlug(string $slug): ?Course
    {
        return Course::query()->where('slug', $slug)->first();
    }

    /** The course teacher commands of $group (`/start_training`, `/start_exam`) work in. */
    public function courseForGroup(TelegramGroup $group): Course
    {
        if ($group->course_id !== null) {
            $course = $group->course;
            if ($course !== null) {
                return $course;
            }
        }

        return $this->defaultCourse();
    }

    public function findStage(Course $course, int $stageNumber): ?Stage
    {
        return Stage::query()
            ->where('course_id', $course->id)
            ->where('number', $stageNumber)
            ->first();
    }

    public function findLesson(Stage $stage, int $lessonNumber): ?Lesson
    {
        return Lesson::query()
            ->where('stage_id', $stage->id)
            ->where('number', $lessonNumber)
            ->first();
    }

    /**
     * The first lesson of the course (in study order) that still has an active word the
     * learner has never been served — where `daily_review` takes its new words from
     * (docs/12 §7.3). Null when the learner has seen the whole course.
     *
     * Not used by the picker (it selects new words with one query); kept for task 1.3b
     * (`TodayService`: `active_course.next_lesson`).
     */
    public function nextLessonWithNewWords(Course $course, Learner $learner): ?Lesson
    {
        return Lesson::query()
            ->select('lessons.*')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->whereExists(fn (QueryBuilder $q): QueryBuilder => $q
                ->from('words')
                ->whereColumn('words.lesson_id', 'lessons.id')
                ->where('words.is_active', true)
                ->whereNotExists(fn (QueryBuilder $seen): QueryBuilder => $seen
                    ->from('word_repetitions')
                    ->whereColumn('word_repetitions.word_id', 'words.id')
                    ->where('word_repetitions.learner_id', $learner->id)))
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->with('stage')
            ->first();
    }

    /**
     * Lessons of the course in study order `(stage.number, lesson.number)`,
     * with their `stage` loaded.
     *
     * @return Collection<int, Lesson>
     */
    public function lessons(Course $course, ?int $limit = null): Collection
    {
        $query = Lesson::query()
            ->select('lessons.*')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('stages.course_id', $course->id)
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->with('stage');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Public published courses in catalog order, localized for $locale (title / description fall
     * back to ru). Cached for 10 minutes; {@see self::forgetPublished()} drops it whenever a
     * course changes (publication, archiving, editing, recount).
     *
     * @return list<CatalogCourse>
     */
    public function published(string $locale): array
    {
        $locale = $this->locales->isSupported($locale) ? $locale : $this->locales->default();

        /** @var list<array{id:int, slug:string, title:string, description:string|null, level:string|null, exam_tag:string|null, words_count:int, is_featured:bool}> $rows */
        $rows = Cache::remember(
            self::PUBLISHED_CACHE_PREFIX.$locale,
            self::PUBLISHED_CACHE_TTL,
            fn (): array => Course::query()
                ->where('status', 'published')
                ->where('visibility', 'public')
                ->orderBy('position')
                ->orderBy('id')
                ->get()
                ->map(fn (Course $course): array => $this->present($course, $locale)->toArray())
                ->all(),
        );

        return array_map(CatalogCourse::fromArray(...), $rows);
    }

    public function forgetPublished(): void
    {
        Cache::deleteMultiple(array_map(
            fn (string $locale): string => self::PUBLISHED_CACHE_PREFIX.$locale,
            $this->locales->supported(),
        ));
    }

    /**
     * THE availability rule: a course is available (openable by slug, selectable as the active
     * course, a source of lessons and new words) when it is published and not private. Its SQL
     * twin is the `Course::available()` scope — keep them in step.
     *
     * One exception: with `$learner` given, a private course the learner OWNS (their own deck,
     * `owner_type = learner`, docs/13 §8.1) is available to that learner and to nobody else. SQL twin:
     * `Course::availableTo()`. Callers without a learner (groups, deep links) never see a private course.
     */
    public function isAvailable(Course $course, ?Learner $learner = null): bool
    {
        if ($course->status !== 'published') {
            return false;
        }

        return $course->visibility !== 'private' || ($learner !== null && $this->isOwnedBy($course, $learner));
    }

    /** A learner's own deck: `owner_type = learner` and `owner_id` is this learner. */
    public function isOwnedBy(Course $course, Learner $learner): bool
    {
        return $course->owner_type === 'learner' && $course->owner_id === $learner->id;
    }

    public function findAvailable(string $slug, ?Learner $learner = null): ?Course
    {
        $query = Course::query()->where('slug', $slug);
        $learner === null ? $query->available() : $query->availableTo($learner->id);

        return $query->first();
    }

    /**
     * The course onboarding suggests for a goal and a self-assessed level (docs/12 §3.2): among
     * public published courses with `exam_tag = goal`, the one of the same level first, then
     * featured ones, then catalog order. Null when no course fits the goal — the caller falls
     * back to the default course.
     */
    public function recommend(?string $goal, ?string $level): ?Course
    {
        if ($goal === null || $goal === '') {
            return null;
        }

        $matching = array_values(array_filter(
            $this->published($this->locales->default()),
            fn (CatalogCourse $c): bool => $c->examTag === $goal,
        ));

        // usort is stable: equal candidates stay in catalog order (position, id).
        usort($matching, fn (CatalogCourse $a, CatalogCourse $b): int => [$b->level === $level && $level !== null, $b->isFeatured]
            <=> [$a->level === $level && $level !== null, $a->isFeatured]);

        return $matching === [] ? null : Course::query()->find($matching[0]->id);
    }

    /**
     * The catalog with the learner's progress. The learner's own active course is listed even when
     * it is `unlisted` (chosen by a link) or the learner's own deck, as long as it is still available.
     *
     * @return list<CourseListing>
     */
    public function forLearner(Learner $learner): array
    {
        $locale = $this->locales->forLearner($learner);
        $courses = $this->published($locale);
        $activeId = $learner->active_course_id;

        if ($activeId !== null && collect($courses)->doesntContain(fn (CatalogCourse $c): bool => $c->id === $activeId)) {
            $active = Course::query()->availableTo($learner->id)->whereKey($activeId)->first();
            if ($active !== null) {
                $courses[] = $this->present($active, $locale);
            }
        }

        $progress = $this->courseProgress($learner, array_map(fn (CatalogCourse $c): int => $c->id, $courses));

        return array_map(
            fn (CatalogCourse $course): CourseListing => new CourseListing(
                $course,
                $progress[$course->id] ?? new LearnerProgress(0, 0),
                $course->id === $activeId,
            ),
            $courses,
        );
    }

    /** The course title in $locale (falls back to the default locale, then to the slug) — no progress is computed. */
    public function title(Course $course, string $locale): string
    {
        return $this->localized($course->title, $locale) ?? $course->slug;
    }

    /**
     * Catalog rows for the given courses (the learner's decks), in the order given, with ONE progress query.
     *
     * @param  iterable<Course>  $courses
     * @return list<CourseListing>
     */
    public function listings(iterable $courses, Learner $learner): array
    {
        $list = [];
        foreach ($courses as $course) {
            $list[] = $course;
        }

        $locale = $this->locales->forLearner($learner);
        $progress = $this->courseProgress($learner, array_map(fn (Course $c): int => $c->id, $list));

        return array_map(
            fn (Course $course): CourseListing => new CourseListing(
                $this->present($course, $locale),
                $progress[$course->id] ?? new LearnerProgress(0, 0),
                $learner->active_course_id === $course->id,
            ),
            $list,
        );
    }

    /** A single catalog row (the course the learner has just chosen). */
    public function listing(Course $course, Learner $learner): CourseListing
    {
        return new CourseListing(
            $this->present($course, $this->locales->forLearner($learner)),
            $this->courseProgress($learner, [$course->id])[$course->id] ?? new LearnerProgress(0, 0),
            $learner->active_course_id === $course->id,
        );
    }

    public function detail(Course $course, Learner $learner): CourseDetail
    {
        $lessons = $this->lessonsWithProgress($course, $learner);

        $done = 0;
        $total = 0;
        foreach ($lessons as $lesson) {
            $done += $lesson->progress->done;
            $total += $lesson->progress->total;
        }

        return new CourseDetail(
            $this->present($course, $this->locales->forLearner($learner)),
            new LearnerProgress($done, $total),
            $learner->active_course_id === $course->id,
            $lessons,
        );
    }

    /**
     * Lessons of the course in study order with the learner's progress, in one query. Only active
     * words count. Progress is the learner's own (`word_repetitions` is per learner and word), so a
     * word studied in two groups is counted once.
     *
     * @return list<LessonProgress>
     */
    public function lessonsWithProgress(Course $course, Learner $learner): array
    {
        $rows = DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->leftJoin('words', function (JoinClause $join): void {
                $join->on('words.lesson_id', '=', 'lessons.id')->where('words.is_active', '=', true);
            })
            ->leftJoin('word_repetitions', function (JoinClause $join) use ($learner): void {
                $join->on('word_repetitions.word_id', '=', 'words.id')
                    ->where('word_repetitions.learner_id', '=', $learner->id)
                    ->where('word_repetitions.repetitions', '>=', 1);
            })
            ->where('stages.course_id', $course->id)
            ->groupBy('stages.number', 'stages.title', 'lessons.id', 'lessons.number', 'lessons.title')
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->selectRaw('stages.number as stage_number, stages.title as stage_title, lessons.id as lesson_id, lessons.number as lesson_number, lessons.title as lesson_title, COUNT(words.id) as total, COUNT(word_repetitions.id) as done')
            ->get();

        $lessons = [];
        foreach ($rows as $row) {
            $lessons[] = new LessonProgress(
                (int) $row->stage_number,
                (string) $row->stage_title,
                (int) $row->lesson_id,
                (int) $row->lesson_number,
                $row->lesson_title === null ? null : (string) $row->lesson_title,
                new LearnerProgress((int) $row->done, (int) $row->total),
            );
        }

        return $lessons;
    }

    /**
     * Active-word progress per course in a single aggregate query.
     *
     * @param  list<int>  $courseIds
     * @return array<int, LearnerProgress> course_id => progress (a course without words is absent)
     */
    private function courseProgress(Learner $learner, array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = DB::table('words')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->leftJoin('word_repetitions', function (JoinClause $join) use ($learner): void {
                $join->on('word_repetitions.word_id', '=', 'words.id')
                    ->where('word_repetitions.learner_id', '=', $learner->id)
                    ->where('word_repetitions.repetitions', '>=', 1);
            })
            ->where('words.is_active', true)
            ->whereIn('stages.course_id', $courseIds)
            ->groupBy('stages.course_id')
            ->selectRaw('stages.course_id as course_id, COUNT(words.id) as total, COUNT(word_repetitions.id) as done')
            ->get();

        $progress = [];
        foreach ($rows as $row) {
            $progress[(int) $row->course_id] = new LearnerProgress((int) $row->done, (int) $row->total);
        }

        return $progress;
    }

    private function present(Course $course, string $locale): CatalogCourse
    {
        return new CatalogCourse(
            $course->id,
            $course->slug,
            $this->title($course, $locale),
            $this->localized($course->description, $locale),
            $course->level,
            $course->exam_tag,
            $course->words_count,
            $course->is_featured,
        );
    }

    /**
     * @param  array<array-key, mixed>  $map  locale => text
     */
    private function localized(array $map, string $locale): ?string
    {
        foreach ([$locale, $this->locales->fallback()] as $key) {
            $text = $map[$key] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }
}
