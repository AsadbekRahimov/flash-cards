<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\DayBounds;
use App\Domain\Learners\DTO\DayPlan;
use App\Domain\Learning\Services\LessonReviewService;
use App\Domain\Operations\Support\ScalarBatch;
use App\Domain\Practice\Services\PracticeAccess;
use App\Models\Course;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The plan of the learner's day: lesson -> review of the lesson -> game on its words (docs/04 A.2a `plan`, docs/31 §5б, the mockup
 * `docs/design/2026-11-25-redesign`). "Today" is the learner's local calendar day - the day of the goal and the streak
 * ({@see LearnerClock::dayBoundsUtc()}).
 *
 * It costs NO statement of its own on `GET /today` (docs/19 K10): the sessions of the day ride in the statement of the open sessions
 * ({@see constrainSessions()}), the words of the lesson and "played" in the statement of scalars ({@see addScalars()}), the next lesson
 * and the title of the lesson come from the course block already read. The bot (the reminder after a lesson) asks {@see forLearner()}:
 * the same rules in two statements (three with the course).
 *
 *   - the lesson of today: the lesson of the latest personal `lesson` session played to its end today (`completed_at` in the day, also
 *     when it ended on the Free quota) whose words the learner met today ({@see LessonReviewService::wordsMetTodayQuery()} counts > 0);
 *   - review done: a `lesson_review` of that lesson begun today and played to its end (`completed_at`);
 *   - game played: a practice session (any mode) on that lesson begun today with at least one answer;
 *   - the course of the lesson (its slug - where the review and the game open; the lesson of today may be of another course than the
 *     active one: an assignment of a center, a course switched afterwards) rides as a third scalar (`plan_course`), no statement.
 */
final class DayPlanService
{
    public function __construct(
        private readonly LessonReviewService $reviews,
        private readonly PracticeAccess $practice,
        private readonly EntitlementResolver $entitlements,
        private readonly CourseCatalogService $catalog,
    ) {}

    public function day(Learner $learner, ?CarbonImmutable $now = null): DayBounds
    {
        return $this->reviews->day($learner, $now);
    }

    /**
     * Adds to a statement of the learner's `training_sessions` (already `where learner_id = ?`) the sessions the plan reads: the
     * personal lessons played to their end in the day and the reviews of a lesson begun in it. As an OR group: the caller's own
     * conditions stay. The caller selects `id, mode, lesson_id, status, started_at, expires_at, completed_at`.
     *
     * @param  Builder<TrainingSession>  $query
     */
    public function constrainSessions(Builder $query, DayBounds $day): void
    {
        $query->orWhere(fn (Builder $lesson) => $lesson
            ->where('mode', TrainingSession::MODE_LESSON)
            ->where('completed_at', '>=', $day->startUtc));
        $query->orWhere(fn (Builder $review) => $review
            ->where('mode', TrainingSession::MODE_LESSON_REVIEW)
            ->where('started_at', '>=', $day->startUtc));
    }

    /**
     * The lesson the plan is about: the one of the latest lesson session played to its end in the day. Whether the learner met new
     * words in it today is the scalar `plan_words` ({@see addScalars()}).
     *
     * @param  iterable<TrainingSession>  $sessions
     */
    public function candidateLesson(iterable $sessions, DayBounds $day): ?int
    {
        $latest = null;
        $latestAt = null;
        foreach ($sessions as $session) {
            $completedAt = $session->completed_at;
            if ($session->mode !== TrainingSession::MODE_LESSON || $session->lesson_id === null || $completedAt === null || $completedAt->lt($day->startUtc)) {
                continue;
            }
            if ($latest === null || $latestAt === null || $completedAt->gt($latestAt) || ($completedAt->eq($latestAt) && $session->id > $latest->id)) {
                $latest = $session;
                $latestAt = $completedAt;
            }
        }

        return $latest === null ? null : (int) $latest->lesson_id;
    }

    /** The three scalars of the plan for the candidate lesson (its words, "played", the slug of its course), into the caller's ONE statement. */
    public function addScalars(ScalarBatch $batch, Learner $learner, int $lessonId, DayBounds $day): void
    {
        $batch->countLimited('plan_words', $this->reviews->wordsMetTodayQuery((int) $learner->id, $lessonId, $day->startUtc)->limit($this->reviews->maxWords()));
        $batch->exists('plan_game', $this->gamePlayedQuery((int) $learner->id, $lessonId, $day));
        $batch->value('plan_course', $this->lessonCourseQuery($lessonId));
    }

    /** Whether the learner may open the game of step 3: the practice is open to them (not behind Pro) and `pairs_timed` is on. */
    public function gameAvailable(Learner $learner, Entitlement $entitlement): bool
    {
        return self::gameAvailableFrom($this->practice->describe($learner, $entitlement));
    }

    /** @param  array{available: bool, pro_required: bool, modes: list<string>, battle: bool, pro_promise: string}  $practice  {@see PracticeAccess::describe()} */
    public static function gameAvailableFrom(array $practice): bool
    {
        return $practice['available'] && ! $practice['pro_required'] && in_array(DayPlan::GAME_MODE, $practice['modes'], true);
    }

    /**
     * @param  iterable<TrainingSession>  $sessions  the sessions of {@see constrainSessions()} (other modes are ignored)
     * @param  int|null  $candidate  {@see candidateLesson()}
     * @param  array<string, mixed>  $scalars  the row of the statement of scalars, with `plan_words` / `plan_game` / `plan_course` when a candidate was added
     * @param  array<int, string|null>  $titles  lesson id => title, of the active course (CourseToday::$lessonTitles)
     */
    public function assemble(
        iterable $sessions,
        ?int $candidate,
        array $scalars,
        ?int $nextLessonId,
        ?int $nextLessonWords,
        array $titles,
        bool $gameAvailable,
        CarbonImmutable $now,
    ): DayPlan {
        $words = $candidate === null ? 0 : ScalarBatch::int($scalars, 'plan_words');
        $lessonId = $words > 0 ? $candidate : null;
        $courseSlug = $lessonId === null ? null : ($scalars['plan_course'] ?? null);

        $reviewDone = false;
        $openReview = null;
        if ($lessonId !== null) {
            foreach ($sessions as $session) {
                if ($session->mode !== TrainingSession::MODE_LESSON_REVIEW || (int) $session->lesson_id !== $lessonId) {
                    continue;
                }
                if ($session->completed_at !== null) {
                    $reviewDone = true;
                } elseif ($session->status === 'open' && $session->isLiveAt($now)) {
                    $openReview = (int) $session->id;
                }
            }
        }

        return new DayPlan(
            todayLessonId: $lessonId,
            todayLessonTitle: $lessonId === null ? null : ($titles[$lessonId] ?? null),
            todayWords: $words,
            nextLessonId: $nextLessonId,
            nextLessonWords: $nextLessonId === null ? null : $nextLessonWords,
            reviewEnabled: $this->reviews->enabled(),
            reviewDone: $reviewDone,
            reviewOpenSessionId: $reviewDone ? null : $openReview,
            gameAvailable: $gameAvailable,
            gamePlayed: $lessonId !== null && ScalarBatch::int($scalars, 'plan_game') > 0,
            todayCourseSlug: is_string($courseSlug) && $courseSlug !== '' ? $courseSlug : null,
        );
    }

    /**
     * The plan outside `GET /today` (the bot's reminder after a lesson): the sessions of the day, ONE statement of scalars and - with
     * `$withCourse` - the course block for the next lesson and the title (the cached tree plus the learner's progress over the course).
     */
    public function forLearner(Learner $learner, ?CarbonImmutable $now = null, bool $withCourse = true): DayPlan
    {
        $now ??= CarbonImmutable::now();
        $day = $this->day($learner, $now);

        $sessions = TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where(fn (Builder $q) => $this->constrainSessions($q, $day))
            ->get(['id', 'mode', 'lesson_id', 'status', 'started_at', 'expires_at', 'completed_at']);

        $candidate = $this->candidateLesson($sessions, $day);
        $scalars = [];
        if ($candidate !== null) {
            $batch = new ScalarBatch;
            $this->addScalars($batch, $learner, $candidate, $day);
            $scalars = $batch->run();
        }

        $next = null;
        $titles = [];
        if ($withCourse && $learner->active_course_id !== null) {
            $course = $this->catalog->cachedCourse((int) $learner->active_course_id);
            if ($course instanceof Course && $this->catalog->isAvailable($course, $learner)) {
                $today = $this->catalog->todayCourse($course, $learner);
                $next = $today->nextLesson;
                $titles = $today->lessonTitles;
            }
        }

        return $this->assemble(
            $sessions,
            $candidate,
            $scalars,
            $next?->id,
            $next?->newWords,
            $titles,
            $this->gameAvailable($learner, $this->entitlements->for($learner, $now)),
            $now,
        );
    }

    /** The slug of the course of the lesson (lessons -> stages -> courses, by their primary keys). */
    private function lessonCourseQuery(int $lessonId): QueryBuilder
    {
        return DB::table('lessons')
            ->join('stages', 'stages.id', '=', 'lessons.stage_id')
            ->join('courses', 'courses.id', '=', 'stages.course_id')
            ->where('lessons.id', $lessonId)
            ->select('courses.slug')
            ->limit(1);
    }

    /** A practice session on the lesson begun in the day and answered at least once (served by `(learner_id, status, started_at)`). */
    private function gamePlayedQuery(int $learnerId, int $lessonId, DayBounds $day): QueryBuilder
    {
        return DB::table('practice_sessions')
            ->where('learner_id', $learnerId)
            ->where('lesson_id', $lessonId)
            ->where('started_at', '>=', $day->startUtc)
            ->where('answered', '>', 0);
    }
}
