<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Learners\DTO\ReminderContent;
use App\Domain\Learners\Enums\ReminderKind;
use App\Domain\Learning\Services\LearnerStatsService;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * Decides what a personal reminder says at the moment it is sent (docs/12 §7.5):
 *
 *  1. `due >= learning.reminders.due_threshold` (default 1) → "N words to review";
 *     `due` is {@see LearnerStatsService::dueCount()} — the same DueRepetitionCriteria
 *     as `due_count` on the "Today" screen, so the message matches what the TWA shows;
 *  2. otherwise, the daily-goal streak is at risk (`current > 0 && !metToday`) →
 *     "your streak of N days is about to break";
 *  3. otherwise `null`: nothing to say today (the dispatcher has already moved
 *     `next_reminder_at` to tomorrow, which is what we want).
 *
 * Runs outside any transaction: {@see StreakService::current()} may repair the
 * `learners` projection with its own UPDATE (lock order, docs/12 §3.1).
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

        $due = $this->stats->dueCount($learner, $now);
        $streak = $this->streaks->current(
            $learner,
            $this->clock->today($learner, $now),
            $this->dailyStats->today($learner, $now),
        );

        if ($due >= $this->threshold()) {
            return new ReminderContent(ReminderKind::Review, $due, $streak->current);
        }

        if ($streak->atRisk) {
            return new ReminderContent(ReminderKind::StreakAtRisk, $due, $streak->current);
        }

        return null;
    }

    public function threshold(): int
    {
        return max(1, (int) config('learning.reminders.due_threshold', 1));
    }
}
