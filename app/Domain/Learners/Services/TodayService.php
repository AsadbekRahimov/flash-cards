<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\NewWordQuota;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Content\Services\DeckTitles;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Learners\DTO\DailyGoal;
use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learners\DTO\TodayCourse;
use App\Domain\Learners\DTO\TodayLesson;
use App\Domain\Learners\DTO\TodaySummary;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

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
        private readonly DeckTitles $deckTitles,
    ) {}

    public function summary(Learner $learner, ?CarbonImmutable $now = null): TodaySummary
    {
        $now ??= CarbonImmutable::now();

        $today = $this->clock->today($learner, $now);
        // Today's row is read once for the whole screen: the settle of the weekly freeze and the goal / streak block share it.
        $stats = $this->dailyStats->today($learner, $now);

        $this->handOutWeeklyFreeze($learner, $today, $stats, $now);

        return new TodaySummary(
            dueCount: $this->stats->dueCount($learner, $now),
            newWordsRemaining: $this->quota->remaining($learner, $now),
            progress: $this->progressOn($learner, $today, $stats, $now),
            activeCourse: $this->activeCourse($learner),
            openSessionId: $this->openDailySessionId($learner, $now),
            entitlement: $this->entitlements->for($learner, $now),
            onboardingCompleted: $learner->onboarding_state === 'done',
            league: $this->leagues->todayLine($learner, $now),
        );
    }

    /**
     * Goal and streak of the learner's current local day. `$todayStats` is passed to the streak so
     * a goal met today but not yet projected onto `learners` is repaired on read.
     */
    public function progress(Learner $learner, ?CarbonImmutable $now = null): DailyProgress
    {
        return $this->progressOn($learner, $this->clock->today($learner, $now), $this->dailyStats->today($learner, $now), $now);
    }

    private function progressOn(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $stats, ?CarbonImmutable $now): DailyProgress
    {
        return new DailyProgress(
            new DailyGoal(
                target: max(1, $learner->daily_goal),
                done: $stats->reviews_count ?? 0,
                met: $stats?->goal_met_at !== null,
            ),
            $this->streaks->current($learner, $today, $stats, $now),
        );
    }

    /**
     * The weekly freeze is handed out lazily here (docs/13 §7.2), once per local week, and only to a plan that
     * has freezes: for any other learner refill() does nothing, so there is nothing to settle ahead of it either
     * (the goal / streak block settles the series, once). `streak_freezes_refilled_on` only ever moves forward, so
     * a model that already shows this week cannot be behind the database and no statement is spent on it.
     */
    private function handOutWeeklyFreeze(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $stats, CarbonImmutable $now): void
    {
        if (! $this->freezes->enabled($learner, $now)) {
            return;
        }

        $weekStart = $today->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        if ($learner->streak_freezes_refilled_on !== null && $learner->streak_freezes_refilled_on->toDateString() >= $weekStart) {
            return;
        }

        // A series that the stock could not cover BEFORE this refill is already burnt (docs/13 §12 q.16):
        // settle it first, so the freeze handed out below cannot save it retroactively.
        $this->streaks->current($learner, $today, $stats, $now);
        $this->freezes->refill($learner, $today, $now);
    }

    /** Null when the learner has no active course, or it is no longer available (CourseCatalogService::isAvailable). */
    private function activeCourse(Learner $learner): ?TodayCourse
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        /** @var Course|null $course */
        $course = $learner->activeCourse;
        if ($course === null || ! $this->catalog->isAvailable($course, $learner)) {
            return null;
        }

        $listing = $this->catalog->listing($course, $learner);
        $lesson = $this->catalog->nextLessonWithNewWords($course, $learner);

        return new TodayCourse(
            id: $course->id,
            slug: $course->slug,
            title: $listing->course->title,
            learned: $listing->progress->done,
            total: $listing->progress->total,
            nextLesson: $lesson === null ? null : new TodayLesson(
                $lesson->id,
                $lesson->stage?->number,
                $lesson->number,
                $this->deckTitles->lesson($course, $learner, $lesson->number, $lesson->title),
            ),
        );
    }

    private function openDailySessionId(Learner $learner, CarbonImmutable $now): ?int
    {
        /** @var TrainingSession|null $session */
        $session = TrainingSession::query()
            ->where('learner_id', $learner->id)
            ->where('mode', TrainingSession::MODE_DAILY_REVIEW)
            ->where('status', 'open')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->first(['id']);

        return $session?->id;
    }
}
