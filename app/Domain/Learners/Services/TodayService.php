<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Learners\DTO\DailyGoal;
use App\Domain\Learners\DTO\DailyProgress;
use App\Domain\Learners\DTO\TodayCourse;
use App\Domain\Learners\DTO\TodayLesson;
use App\Domain\Learners\DTO\TodaySummary;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "Today" screen (`GET /api/twa/today`, docs/12 §7.3) and the goal / streak block of the
 * `/finish` summary. Read-only: the numbers are written by the review transaction
 * (DailyStatsService, StreakService); here they are only put together for the learner's local day.
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
    ) {}

    public function summary(Learner $learner, ?CarbonImmutable $now = null): TodaySummary
    {
        $now ??= CarbonImmutable::now();

        return new TodaySummary(
            dueCount: $this->stats->dueCount($learner, $now),
            // Filled by the new-words quota of task 1.4b; null = unlimited until then.
            newWordsRemaining: null,
            progress: $this->progress($learner, $now),
            activeCourse: $this->activeCourse($learner),
            openSessionId: $this->openDailySessionId($learner, $now),
            entitlement: $this->entitlements->for($learner, $now),
            onboardingCompleted: $learner->onboarding_state === 'done',
        );
    }

    /**
     * Goal and streak of the learner's current local day. `$todayStats` is passed to the streak so
     * a goal met today but not yet projected onto `learners` is repaired on read.
     */
    public function progress(Learner $learner, ?CarbonImmutable $now = null): DailyProgress
    {
        $today = $this->clock->today($learner, $now);
        $stats = $this->dailyStats->today($learner, $now);

        return new DailyProgress(
            new DailyGoal(
                target: $learner->daily_goal,
                done: $stats->reviews_count ?? 0,
                met: $stats?->goal_met_at !== null,
            ),
            $this->streaks->current($learner, $today, $stats),
        );
    }

    /** Null when the learner has no active course, or it is no longer published. */
    private function activeCourse(Learner $learner): ?TodayCourse
    {
        if ($learner->active_course_id === null) {
            return null;
        }

        /** @var Course|null $course */
        $course = $learner->activeCourse;
        if ($course === null || $course->status !== 'published') {
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
                $lesson->title,
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
