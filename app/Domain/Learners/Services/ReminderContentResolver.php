<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;

/**
 * Decides what a personal reminder says at the moment it is sent (docs/12 §7.5).
 *
 * `idle` = whole local days between today and the learner's last local day with reviews
 * (`learner_daily_stats.reviews_count > 0`; never studied → counted from the local day of
 * `created_at`; studied today → 0). First matching rule wins:
 *
 *  1. `idle > stop_after_days` (60) → `null`: whoever left for good is not nagged;
 *  2. `idle > daily_until_days` (7) and not in `sparse_days` → `null`: a rare reminder;
 *  3. `idle >= winback_from_days` (3) → `Inactive` ("N days without lessons", plus the due
 *     count when there are due words); never studied → `NeverStarted`;
 *  4. `due >= learning.reminders.due_threshold` (1) → `Review`; `due` is
 *     {@see LearnerStatsService::dueCount()} — the same DueRepetitionCriteria as `due_count`
 *     on the "Today" screen, so the message matches what the TWA shows;
 *  5. the daily-goal streak is at risk (`current > 0 && !metToday`) → `StreakAtRisk`;
 *  6. no reviews today yet: never studied → `NeverStarted`, otherwise → `NewWords`;
 *  7. otherwise `null` (studied today, nothing to repeat).
 *
 * A learner without an active course who would hear `NeverStarted` / `NewWords` gets
 * `ChooseCourse` instead. The dispatcher has already moved `next_reminder_at` to tomorrow,
 * so `null` simply means "silent today".
 *
 * Runs outside any transaction: {@see StreakService::current()} may repair the
 * `learners` projection with its own UPDATE (lock order, docs/12 §3.1). Besides the
 * existing reads it costs one `max(date)` query (none when the learner studied today).
 */
final class ReminderContentResolver
{
    public function __construct(
        private readonly LearnerStatsService $stats,
        private readonly LearnerClock $clock,
        private readonly DailyStatsService $dailyStats,
        private readonly StreakService $streaks,
    ) {}

    public function resolve(Learner $learner, ?CarbonImmutable $now = null): ?ReminderContent
    {
        $now ??= CarbonImmutable::now();

        $today = $this->clock->today($learner, $now);
        $todayStats = $this->dailyStats->today($learner, $now);
        $reviewedToday = $todayStats !== null && $todayStats->reviews_count > 0;

        $lastStudyDate = $reviewedToday ? $today->toDateString() : $this->lastStudyDate($learner);
        $idle = $this->idleDays($learner, $today, $lastStudyDate);

        if ($idle > $this->stopAfterDays()) {
            return null;
        }

        if ($idle > $this->dailyUntilDays() && ! in_array($idle, $this->sparseDays(), true)) {
            return null;
        }

        $due = $this->stats->dueCount($learner, $now);
        $streak = $this->streaks->current($learner, $today, $todayStats);
        $neverStudied = $lastStudyDate === null;

        if ($idle >= $this->winbackFromDays()) {
            return $neverStudied
                ? $this->gentle($learner, ReminderKind::NeverStarted, $due, $streak->current, $idle)
                : new ReminderContent(ReminderKind::Inactive, $due, $streak->current, $idle);
        }

        if ($due >= $this->threshold()) {
            return new ReminderContent(ReminderKind::Review, $due, $streak->current, $idle);
        }

        if ($streak->atRisk) {
            return new ReminderContent(ReminderKind::StreakAtRisk, $due, $streak->current, $idle);
        }

        if ($reviewedToday) {
            return null;
        }

        return $this->gentle($learner, $neverStudied ? ReminderKind::NeverStarted : ReminderKind::NewWords, $due, $streak->current, $idle);
    }

    public function threshold(): int
    {
        return max(1, (int) config('learning.reminders.due_threshold', 1));
    }

    public function winbackFromDays(): int
    {
        return max(1, (int) config('learning.reminders.winback_from_days', 3));
    }

    public function dailyUntilDays(): int
    {
        return max(1, (int) config('learning.reminders.daily_until_days', 7));
    }

    public function stopAfterDays(): int
    {
        return max(1, (int) config('learning.reminders.stop_after_days', 60));
    }

    /** @return list<int> the idle days (beyond `daily_until_days`) on which a reminder still goes out */
    public function sparseDays(): array
    {
        $days = config('learning.reminders.sparse_days', []);

        return is_array($days)
            ? array_values(array_filter(array_map('intval', $days), static fn (int $day): bool => $day > 0))
            : [];
    }

    /** "Start" message; for a learner without an active course the call to choose one. */
    private function gentle(Learner $learner, ReminderKind $kind, int $due, int $streak, int $idle): ReminderContent
    {
        return new ReminderContent(
            $learner->active_course_id === null ? ReminderKind::ChooseCourse : $kind,
            $due,
            $streak,
            $idle,
        );
    }

    /** `Y-m-d` of the last local day with reviews, `null` if the learner never studied. One indexed `max(date)`. */
    private function lastStudyDate(Learner $learner): ?string
    {
        $last = LearnerDailyStats::query()
            ->where('learner_id', $learner->id)
            ->where('reviews_count', '>', 0)
            ->max('date');

        return is_string($last) ? substr($last, 0, 10) : null;
    }

    /** Whole local days from the last study day (or the local day of registration) to `$today`; never negative. */
    private function idleDays(Learner $learner, CarbonImmutable $today, ?string $lastStudyDate): int
    {
        $from = $lastStudyDate
            ?? ($learner->created_at?->setTimezone($today->getTimezone())->toDateString() ?? $today->toDateString());

        $days = CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($today->toDateString(), 'UTC'), false);

        return max(0, (int) round($days));
    }
}
