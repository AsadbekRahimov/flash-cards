<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\DTO\CatalogCourse;
use App\Domain\Content\DTO\CourseDetail;
use App\Domain\Content\DTO\CourseListing;
use App\Domain\Content\DTO\LearnerProgress;
use App\Domain\Content\DTO\LessonProgress;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Operations\Support\PureCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * The page of one course (`detail()`) is the cached TREE of the course (modules, lessons, the number of active words of
 * each - nothing of the learner, docs/19 K3) plus the learner's own `done` per lesson, one query that follows what they have
 * studied. The tree is dropped by {@see forgetCourseTree()} whenever a course's words, lessons, modules or status change.
 */
final class CourseCatalogService
{
    private const PUBLISHED_CACHE_PREFIX = 'catalog:courses:published:v1:';

    private const PUBLISHED_CACHE_TTL = 600;

    /** `catalog:course:v1:{course id}:{locale}:n{data version}`: the tree of one course in one locale (docs/19 K3). */
    private const COURSE_TREE_PREFIX = 'catalog:course:v1:';

    private const COURSE_TREE_TTL = 1800;

    /** The columns of `stages` in table order (`title_uz` was added last), selected beside a lesson's under the prefix `stage__` (a join cannot return two `id`s). */
    private const STAGE_COLUMNS = ['id', 'course_id', 'number', 'title', 'description', 'created_at', 'updated_at', 'title_uz'];

    /** The columns of `courses` a lesson row carries under `course__`: whose the course is and whether it is open. */
    private const COURSE_COLUMNS = ['id', 'slug', 'owner_type', 'owner_id', 'visibility', 'status'];

    public function __construct(private readonly LocaleResolver $locales, private readonly DeckTitles $deckTitles) {}

    /**
     * The platform course that receives demo content and imports without an
     * explicit course, and serves groups whose `course_id` is NULL. Created
     * on first use (race-safe).
     */
    public function defaultCourse(): Course
    {
        return Course::query()->firstOrCreate(
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
     *
     * One anti-join at the level of WORDS (never-served active words of the course, `LIMIT 1` in study
     * order) instead of a correlated `EXISTS` per lesson: the lookup is one pass over the words of the
     * course and the learner's rows, not lessons × the learner's cards. The lesson comes with its `stage`
     * loaded from the very same row (the join is there anyway for the order), not by a second query.
     */
    public function nextLessonWithNewWords(Course $course, Learner $learner): ?Lesson
    {
        /** @var Lesson|null $lesson */
        $lesson = Lesson::query()
            ->select('lessons.*')
            ->addSelect(self::stageColumns())
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('words', 'words.lesson_id', '=', 'lessons.id')
            ->where('stages.course_id', $course->id)
            ->where('words.is_active', true)
            ->whereNotExists(fn (QueryBuilder $seen): QueryBuilder => $seen
                ->from('word_repetitions')
                ->whereColumn('word_repetitions.word_id', 'words.id')
                ->where('word_repetitions.learner_id', $learner->id))
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->first();

        return $lesson === null ? null : $this->withJoinedRelations($lesson);
    }

    /**
     * A lesson with its stage and the course facts that decide access and titles, read in ONE query (the three
     * joins) instead of the three of `loadMissing('stage.course')`: the lesson is a whole model, its `stage` is a
     * whole model, and `stage.course` is a Course with the access columns only (`id, slug, owner_type, owner_id,
     * visibility, status`) - no title / description; whoever needs the content of the course reads the course.
     * Null when the lesson does not exist.
     */
    public function lessonWithContext(int $lessonId): ?Lesson
    {
        $lesson = $this->lessonContextQuery()->where('lessons.id', $lessonId)->first();

        return $lesson === null ? null : $this->withJoinedRelations($lesson);
    }

    /**
     * @param  list<int>  $lessonIds
     * @return Collection<int, Lesson> indexed by lesson id
     */
    public function lessonsWithContext(array $lessonIds): Collection
    {
        return $this->lessonContextQuery()
            ->whereIn('lessons.id', $lessonIds)
            ->get()
            ->map($this->withJoinedRelations(...))
            ->keyBy('id');
    }

    public function numberedLesson(Course $course, int $stageNumber, int $lessonNumber): ?Lesson
    {
        $lesson = $this->lessonContextQuery()
            ->where('stages.course_id', $course->id)
            ->where('stages.number', $stageNumber)
            ->where('lessons.number', $lessonNumber)
            ->first();

        return $lesson === null ? null : $this->withJoinedRelations($lesson);
    }

    /** @return Builder<Lesson> */
    private function lessonContextQuery(): Builder
    {
        return Lesson::query()
            ->select('lessons.*')
            ->addSelect(self::stageColumns())
            ->addSelect(self::courseColumns())
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('courses', 'courses.id', '=', 'stages.course_id');
    }

    /**
     * Lessons of the course in study order `(stage.number, lesson.number)`,
     * with their `stage` loaded.
     *
     * @return Collection<int, Lesson>
     */
    public function lessons(Course $course, ?int $limit = null): Collection
    {
        $query = $this->lessonContextQuery()
            ->where('stages.course_id', $course->id)
            ->orderBy('stages.number')
            ->orderBy('lessons.number');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->map($this->withJoinedRelations(...));
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
        $rows = PureCache::store()->remember(
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
        PureCache::store()->deleteMultiple(array_map(
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

        $totals = [];
        foreach ($courses as $course) {
            $totals[$course->id] = $course->wordsCount;
        }
        $progress = $this->courseProgress($learner, $totals);

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
        $totals = [];
        foreach ($list as $course) {
            $totals[$course->id] = $course->words_count;
        }
        $progress = $this->courseProgress($learner, $totals);

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
            $this->courseProgress($learner, [$course->id => $course->words_count])[$course->id] ?? new LearnerProgress(0, 0),
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
     * Lessons of the course in study order with the learner's progress. Only active words count. Progress is the learner's
     * own (`word_repetitions` is per learner and word), so a word studied in two groups is counted once. The lessons and their
     * word totals are the cached {@see courseTree()}; `done` is the learner's one query ({@see lessonsDone()}).
     *
     * @return list<LessonProgress>
     */
    public function lessonsWithProgress(Course $course, Learner $learner): array
    {
        $tree = $this->courseTree($course, $this->locales->forLearner($learner));
        $done = $this->lessonsDone($course, $learner);

        $lessons = [];
        foreach ($tree as $row) {
            $lessons[] = new LessonProgress(
                $row['stage_number'],
                $row['stage_title'],
                $row['lesson_id'],
                $row['lesson_number'],
                $row['lesson_title'],
                // A word added since the tree was read is in `done` but not yet in `total` for the moment before its drop.
                new LearnerProgress(min($done[$row['lesson_id']] ?? 0, $row['total']), $row['total']),
            );
        }

        return $lessons;
    }

    /**
     * Drops the cached tree of the course in EVERY locale at once by moving its data version on (the version key has no TTL and
     * lives on the non-evicting default store, docs/19 §4; the trees are a pure cache). Called after commit by whatever changes the
     * course's words ({@see CoursePublisher::recount()}), its status ({@see CoursePublisher::transition()}) and its modules and
     * lessons (CourseTreeObserver). A reader that computed the tree from rows older than the change writes to the old version,
     * which nobody reads any more.
     */
    public function forgetCourseTree(int $courseId): void
    {
        Cache::increment(self::courseTreeVersionKey($courseId));
    }

    public static function courseTreeVersionKey(int $courseId): string
    {
        return "catalog:course:ver:{$courseId}";
    }

    public static function courseTreeKey(int $courseId, string $locale): string
    {
        $version = (int) Cache::get(self::courseTreeVersionKey($courseId), 0);

        return self::COURSE_TREE_PREFIX."{$courseId}:{$locale}:n{$version}";
    }

    /**
     * Modules and lessons of the course in study order with the number of ACTIVE words of each, titles as a reader of `$locale`
     * sees them (`title_uz` / `title` picked by DeckTitles, so the ru and the uz entry never share a name): no learner in it, so it
     * is the same for everybody of that locale who may open the course and safe to share (docs/19 K3). A
     * learner's own deck is the exception: it is private, small and edited all the time, so it is read, never cached.
     *
     * @return list<array{stage_number: int, stage_title: string|null, lesson_id: int, lesson_number: int, lesson_title: string|null, total: int}>
     */
    private function courseTree(Course $course, string $locale): array
    {
        if ($course->isLearnerOwned()) {
            return $this->buildCourseTree($course, $locale);
        }

        // The key (with the data version) is made BEFORE the rows are read.
        /** @var list<array{stage_number: int, stage_title: string|null, lesson_id: int, lesson_number: int, lesson_title: string|null, total: int}> */
        return PureCache::store()->remember(
            self::courseTreeKey($course->id, $locale),
            self::COURSE_TREE_TTL,
            fn (): array => $this->buildCourseTree($course, $locale),
        );
    }

    /** @return list<array{stage_number: int, stage_title: string|null, lesson_id: int, lesson_number: int, lesson_title: string|null, total: int}> */
    private function buildCourseTree(Course $course, string $locale): array
    {
        $rows = DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->leftJoin('words', function (JoinClause $join): void {
                $join->on('words.lesson_id', '=', 'lessons.id')->where('words.is_active', '=', true);
            })
            ->where('stages.course_id', $course->id)
            ->groupBy('stages.number', 'stages.title', 'stages.title_uz', 'lessons.id', 'lessons.number', 'lessons.title', 'lessons.title_uz')
            ->orderBy('stages.number')
            ->orderBy('lessons.number')
            ->selectRaw('stages.number as stage_number, stages.title as stage_title, stages.title_uz as stage_title_uz, lessons.id as lesson_id, lessons.number as lesson_number, lessons.title as lesson_title, lessons.title_uz as lesson_title_uz, COUNT(words.id) as total')
            ->get();

        $text = static fn (mixed $value): ?string => $value === null ? null : (string) $value;

        // The names are picked for $locale HERE, so the entry of a locale holds that locale's names and nothing of another's.
        $tree = [];
        foreach ($rows as $row) {
            $tree[] = [
                'stage_number' => (int) $row->stage_number,
                'stage_title' => $this->deckTitles->stageIn($course, $locale, $text($row->stage_title), $text($row->stage_title_uz)),
                'lesson_id' => (int) $row->lesson_id,
                'lesson_number' => (int) $row->lesson_number,
                'lesson_title' => $this->deckTitles->lessonIn($course, $locale, (int) $row->lesson_number, $text($row->lesson_title), $text($row->lesson_title_uz)),
                'total' => (int) $row->total,
            ];
        }

        return $tree;
    }

    /**
     * Active words the learner has answered (`repetitions >= 1`) per lesson of the course - their own rows only, so the size of the
     * query follows what they have studied, not the size of the course.
     *
     * @return array<int, int> lesson id => words learned
     */
    private function lessonsDone(Course $course, Learner $learner): array
    {
        $rows = DB::table('word_repetitions')
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('word_repetitions.learner_id', $learner->id)
            ->where('word_repetitions.repetitions', '>=', 1)
            ->where('words.is_active', true)
            ->where('stages.course_id', $course->id)
            ->groupBy('words.lesson_id')
            ->selectRaw('words.lesson_id as lesson_id, COUNT(*) as done')
            ->get();

        $done = [];
        foreach ($rows as $row) {
            $done[(int) $row->lesson_id] = (int) $row->done;
        }

        return $done;
    }

    /**
     * Active-word progress per course. `total` is the course's own counter of active words
     * (`courses.words_count`, kept by {@see CoursePublisher::recount()} on every path that changes words):
     * the catalog no longer counts the words of all its courses on each open (docs/03 §6). `done` is
     * counted from the LEARNER's rows alone — their answered cards (`repetitions >= 1`) of active words,
     * grouped by course — in one query whose size follows what the learner has studied, not the catalog.
     *
     * @param  array<int, int>  $totals  course_id => active words of the course
     * @return array<int, LearnerProgress> course_id => progress, one entry for each course given
     */
    private function courseProgress(Learner $learner, array $totals): array
    {
        if ($totals === []) {
            return [];
        }

        $rows = DB::table('word_repetitions')
            ->join('words', 'words.id', '=', 'word_repetitions.word_id')
            ->join('lessons', 'lessons.id', '=', 'words.lesson_id')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->where('word_repetitions.learner_id', $learner->id)
            ->where('word_repetitions.repetitions', '>=', 1)
            ->where('words.is_active', true)
            ->whereIn('stages.course_id', array_keys($totals))
            ->groupBy('stages.course_id')
            ->selectRaw('stages.course_id as course_id, COUNT(*) as done')
            ->get();

        $done = [];
        foreach ($rows as $row) {
            $done[(int) $row->course_id] = (int) $row->done;
        }

        $progress = [];
        foreach ($totals as $courseId => $total) {
            $progress[$courseId] = new LearnerProgress($done[$courseId] ?? 0, $total);
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

    /** @return list<string> `stages.<column> as stage__<column>` for the select of a lesson joined with its stage */
    private static function stageColumns(): array
    {
        return array_map(static fn (string $column): string => "stages.{$column} as stage__{$column}", self::STAGE_COLUMNS);
    }

    /** @return list<string> `courses.<column> as course__<column>` */
    private static function courseColumns(): array
    {
        return array_map(static fn (string $column): string => "courses.{$column} as course__{$column}", self::COURSE_COLUMNS);
    }

    /**
     * Turns the prefixed columns a lesson row carries (`stage__*`, and `course__*` when the course was joined too)
     * into the relations `stage` and `stage.course`, and takes them out of the lesson's own attributes, so the
     * lesson is the model a plain `Lesson::find()` would be - with its relations already loaded.
     */
    private function withJoinedRelations(Lesson $lesson): Lesson
    {
        $attributes = $lesson->getAttributes();
        $connection = $lesson->getConnectionName();
        $stage = $this->takePrefixed($attributes, 'stage__', self::STAGE_COLUMNS);
        $course = $this->takePrefixed($attributes, 'course__', self::COURSE_COLUMNS);
        $lesson->setRawAttributes($attributes, true);

        if ($stage !== null) {
            $stageModel = (new Stage)->newFromBuilder($stage, $connection);
            if ($course !== null) {
                $stageModel->setRelation('course', (new Course)->newFromBuilder($course, $connection));
            }
            $lesson->setRelation('stage', $stageModel);
        }

        return $lesson;
    }

    /**
     * Removes the `$prefix`ed columns from `$attributes` and returns them under their own names; null when the row
     * has none (that table was not joined).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    private function takePrefixed(array &$attributes, string $prefix, array $columns): ?array
    {
        if (! array_key_exists($prefix.$columns[0], $attributes)) {
            return null;
        }

        $taken = [];
        foreach ($columns as $column) {
            $taken[$column] = $attributes[$prefix.$column] ?? null;
            unset($attributes[$prefix.$column]);
        }

        return $taken;
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
