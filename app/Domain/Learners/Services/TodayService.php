<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\LearnerBillingService;
use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Game\Services\Runs\DailyCaravanService;
use App\Domain\Learners\DTO\DailyGoal;
use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learners\DTO\TodayCourse;
use App\Domain\Learners\DTO\TodayLesson;
use App\Domain\Learners\DTO\TodaySummary;
use App\Domain\Learning\DTO\LevelQuizWeek;
use App\Domain\Learning\Services\HardRoundService;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Domain\Learning\Services\LevelQuizService;
use App\Domain\Learning\Services\LevelReadinessService;
use App\Domain\Operations\Support\ScalarBatch;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Twa\Services\TodayProfileCache;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The "Today" screen (`GET /api/twa/today`, docs/12 §7.3) and the goal / streak block of the
 * `/finish` summary. The numbers are written by the review transaction (DailyStatsService,
 * StreakService); here they are only put together for the learner's local day.
 *
 * Not strictly read-only: {@see StreakService::current()} may repair a lagging `learners.streak_*`
 * projection with a single `UPDATE learners` or spend a streak freeze, and `summary()` refills the
 * weekly freeze (StreakFreezeService). So `summary()` / `progress()` must not be called
 * inside a transaction that holds `training_sessions` / `word_repetitions` / `learner_daily_stats`
 * (it would add `learners` after them — the lock order docs/12 §3.1 forbids).
 */
final class TodayService
{
    public function __construct(
        private readonly LearnerClock $clock,
        private readonly DailyStatsService $dailyStats,
        private readonly StreakService $streaks,
        private readonly LearnerStatsService $stats,
        private readonly CourseCatalogService $catalog,
        private readonly EntitlementResolver $entitlements,
        private readonly NewWordQuota $quota,
        private readonly StreakFreezeService $freezes,
        private readonly LeagueService $leagues,
        private readonly DailyCaravanService $caravan,
        private readonly LearnerBillingService $billing,
        private readonly PracticeAccess $practice,
        private readonly LevelReadinessService $readiness,
        private readonly LevelQuizService $quizzes,
        private readonly HardRoundService $hardRound,
        private readonly TodayProfileCache $todayProfile,
    ) {}

    /**
     * The statements of the screen (docs/19 K10), for a learner with a course, the game open and the caches warm:
     * the learner (auth), today + yesterday, the open sessions, ONE statement of scalars (the due cards, the quota of Free, the
     * candidates of the round of hard words when the day's round is not known, the quiz of the week), the progress over the lessons
     * of the course, the runs of the game, and - for a member of a group - the card of the class caravan. The course row, the lapse
     * banner and the "in a group" fact come from the pure cache; a cold cache adds the course and the lapse lookup and folds the
     * membership into the scalars.
     */
    public function summary(Learner $learner, ?CarbonImmutable $now = null): TodaySummary
    {
        $now ??= CarbonImmutable::now();

        $today = $this->clock->today($learner, $now);
        $todayDate = $today->toDateString();
        // Today's and yesterday's rows in one statement: the settle of the weekly freeze, the goal / streak block and the
        // "frozen yesterday" of the series share them.
        $rows = $this->dailyStats->forDates($learner, [$todayDate, $today->subDay()->toDateString()]);
        $stats = $rows[$todayDate] ?? null;
        // The plan is resolved once for the whole screen (the entitlement block, the quota, the freezes) - docs/19 K9.
        $entitlement = $this->entitlements->for($learner, $now);

        // A settle may freeze yesterday: the goal / streak block then reads yesterday again rather than the rows above.
        $settled = $this->handOutWeeklyFreeze($learner, $today, $stats, $now, $entitlement, $rows);

        // The learner's version first, then the snapshot keyed by it (a bump landing during this request moves later readers on).
        $version = EntitlementResolver::version((int) $learner->id);
        $snapshot = $this->todayProfile->get($learner, $version);

        // The active course from the pure cache (its row under the course's data version), null when there is none or it is not available.
        $course = $this->availableCourse($learner);

        // The open daily round and the rounds of hard words begun in the day window: ONE statement (docs/06 §14).
        $sessions = $this->openSessions($learner, $now);
        $hardSessions = $sessions->filter(fn (TrainingSession $s): bool => $s->mode === TrainingSession::MODE_HARD_REVIEW);

        // The scalars of the screen in ONE statement, each on its own index (docs/19 K10).
        $batch = (new ScalarBatch)->count('due', $this->stats->dueQuery($learner, $now));
        if ($entitlement->newWordsPerDay !== null) {
            $batch->count('served', $this->quota->servedTodayQuery($learner, $now)); // the Free quota; a plan without a limit counts nothing
        }
        if ($snapshot === null) {
            $batch->count('groups', $this->activeGroupMemberships($learner)); // the cold snapshot: "in a group" rides here, no statement of its own
        }
        $countHard = $this->hardRound->enabled() && $this->hardRound->needsCandidates($learner, $now, $hardSessions, $entitlement);
        if ($countHard) {
            $batch->countLimited('hard_candidates', $this->hardRound->candidatesQueryFor($learner, $now));
        }
        $quizScalars = $course !== null && $this->readiness->enabled() ? $this->quizzes->weekLineScalars($learner, $course, $now) : [];
        foreach ($quizScalars as $alias => $scalar) {
            $batch->value($alias, $scalar);
        }
        $scalars = $batch->run();
        $dueCount = ScalarBatch::int($scalars, 'due');

        if ($snapshot === null) {
            $snapshot = ['lapsed' => $this->billing->lapsed($learner, $entitlement, $now), 'groups' => ScalarBatch::int($scalars, 'groups') > 0];
            $this->todayProfile->put($learner, $version, $snapshot);
        }

        return new TodaySummary(
            dueCount: $dueCount,
            newWordsRemaining: $this->quota->remaining($learner, $now, $entitlement, array_key_exists('served', $scalars) ? ScalarBatch::int($scalars, 'served') : null),
            progress: $this->progressOn($learner, $today, $stats, $now, $entitlement, $settled ? null : $rows),
            activeCourse: $course === null ? null : $this->activeCourse($learner, $course, $quizScalars === [] ? null : $this->quizzes->weekLineFromScalars($learner, $scalars, $now)),
            openSessionId: $sessions->first(fn (TrainingSession $s): bool => $s->mode === TrainingSession::MODE_DAILY_REVIEW)?->id,
            entitlement: $entitlement,
            lapsed: $snapshot['lapsed'],
            onboardingCompleted: $learner->onboarding_state === 'done',
            // The zone the clock really counted the day in (an unknown stored value falls back there): the TWA dates its header by it.
            timezone: $this->clock->timezone($learner)->getName(),
            league: $this->leagues->todayLine($learner, $now),
            game: $this->caravan->todayBlock($learner, $now, $dueCount, inGroup: $snapshot['groups']),
            // The practice card of "Today": the same block as `/me.practice` without its limits (the flags and the plan resolved
            // above, no statement), so the screen does not request `/me` for it (docs/04 A.2a).
            practice: $this->practice->describe($learner, $entitlement),
            // The round of hard words (docs/06 §14): null while switched off; its candidates were counted in the statement above when needed.
            hardRound: $this->hardRound->enabled()
                ? $this->hardRound->availability($learner, $now, $hardSessions, $entitlement, $countHard ? ScalarBatch::int($scalars, 'hard_candidates') : null)->toArray()
                : null,
        );
    }

    /** The learner's active memberships of active groups (docs/18 §12.11: where a caravan of a class can come from), as rows to count. */
    private function activeGroupMemberships(Learner $learner): QueryBuilder
    {
        return DB::table('group_members as gm')
            ->join('telegram_groups as tg', fn (JoinClause $join) => $join->on('tg.id', '=', 'gm.telegram_group_id')->where('tg.status', 'active'))
            ->where('gm.learner_id', $learner->id)
            ->where('gm.status', 'active');
    }

    /**
     * Goal and streak of the learner's current local day. `$todayStats` is passed to the streak so
     * a goal met today but not yet projected onto `learners` is repaired on read.
     */
    public function progress(Learner $learner, ?CarbonImmutable $now = null): DailyProgress
    {
        $today = $this->clock->today($learner, $now);
        $todayDate = $today->toDateString();
        $rows = $this->dailyStats->forDates($learner, [$todayDate, $today->subDay()->toDateString()]);

        return $this->progressOn($learner, $today, $rows[$todayDate] ?? null, $now, null, $rows);
    }

    /** @param  array<string, LearnerDailyStats>|null  $dayRows  today's and yesterday's rows by `Y-m-d`, when read already */
    private function progressOn(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $stats, ?CarbonImmutable $now, ?Entitlement $entitlement, ?array $dayRows): DailyProgress
    {
        return new DailyProgress(
            new DailyGoal(
                target: max(1, $learner->daily_goal),
                done: $stats->reviews_count ?? 0,
                met: $stats?->goal_met_at !== null,
                metAt: $stats?->goal_met_at === null ? null : CarbonImmutable::instance($stats->goal_met_at),
            ),
            $this->streaks->current($learner, $today, $stats, $now, $entitlement, $dayRows),
        );
    }

    /**
     * The weekly freeze is handed out lazily here (docs/13 §7.2), once per local week, and only to a plan that
     * has freezes: for any other learner refill() does nothing, so there is nothing to settle ahead of it either
     * (the goal / streak block settles the series, once). `streak_freezes_refilled_on` only ever moves forward, so
     * a model that already shows this week cannot be behind the database and no statement is spent on it.
     * Returns true when the series was settled here (yesterday may have been frozen by it).
     *
     * @param  array<string, LearnerDailyStats>  $dayRows  today's and yesterday's rows by `Y-m-d`
     */
    private function handOutWeeklyFreeze(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $stats, CarbonImmutable $now, Entitlement $entitlement, array $dayRows): bool
    {
        if (! $this->freezes->enabled($learner, $now, $entitlement)) {
            return false;
        }

        $weekStart = $today->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        if ($learner->streak_freezes_refilled_on !== null && $learner->streak_freezes_refilled_on->toDateString() >= $weekStart) {
            return false;
        }

        // A series that the stock could not cover BEFORE this refill is already burnt (docs/13 §12 q.16):
        // settle it first, so the freeze handed out below cannot save it retroactively.
        $this->streaks->current($learner, $today, $stats, $now, $entitlement, $dayRows);
        $this->freezes->refill($learner, $today, $now);

        return true;
    }

    /**
     * The learner's active course, from the pure cache, or null when there is none or it is no longer available
     * (CourseCatalogService::isAvailable). Set on the model as its relation: whoever reads `$learner->activeCourse` after this pays nothing.
     */
    private function availableCourse(Learner $learner): ?Course
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        $course = $learner->relationLoaded('activeCourse') ? $learner->getRelation('activeCourse') : $this->catalog->cachedCourse((int) $learner->active_course_id);
        if (! $course instanceof Course) {
            return null;
        }
        $learner->setRelation('activeCourse', $course);

        return $this->catalog->isAvailable($course, $learner) ? $course : null;
    }

    /** @param  LevelQuizWeek|null  $quiz  the quiz of the week, read with the scalars of the screen; null while the readiness is off */
    private function activeCourse(Learner $learner, Course $course, ?LevelQuizWeek $quiz): TodayCourse
    {
        $today = $this->catalog->todayCourse($course, $learner);
        $next = $today->nextLesson;

        return new TodayCourse(
            id: $course->id,
            slug: $course->slug,
            title: $today->course->title,
            learned: $today->progress->done,
            total: $today->progress->total,
            nextLesson: $next === null ? null : new TodayLesson($next->id, $next->stageNumber, $next->number, $next->title),
            // The readiness comes from the counts of the statement above; the quiz line rode in the statement of scalars.
            readiness: $this->readiness->enabled() ? $this->readiness->fromCounts($course, $today->stages) : null,
            quiz: $quiz,
        );
    }

    /**
     * The open, unexpired `daily_review` session to continue and - while the round of hard words is on - the `hard_review` sessions
     * begun in the learner's day window (open or not: "played today"), in ONE statement. A daily round played to the end
     * (`completed_at`, docs/06 §13.2) is not one to continue: the next open starts a fresh one.
     *
     * @return Collection<int, TrainingSession>
     */
    private function openSessions(Learner $learner, CarbonImmutable $now): Collection
    {
        $hardFrom = $this->hardRound->enabled() ? $this->hardRound->window($learner, $now)->startUtc : null;

        return TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where(function (Builder $q) use ($now, $hardFrom): void {
                $q->where(fn (Builder $daily) => $daily
                    ->where('mode', TrainingSession::MODE_DAILY_REVIEW)
                    ->where('status', 'open')
                    ->whereNull('completed_at')
                    ->where(fn (Builder $live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', $now)));
                if ($hardFrom !== null) {
                    $q->orWhere(fn (Builder $hard) => $hard
                        ->where('mode', TrainingSession::MODE_HARD_REVIEW)
                        ->where('started_at', '>=', $hardFrom));
                }
            })
            ->get(['id', 'mode', 'status', 'started_at', 'expires_at', 'completed_at', 'meta']);
    }
}
