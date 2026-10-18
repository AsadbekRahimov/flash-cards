<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Models\Learner;
use Carbon\CarbonImmutable;

/**
 * Per-local-day aggregates of a learner (`learner_daily_stats`, docs/12 §1.6, §3.1).
 *
 * Task 1.3a wires the hook: ReviewSubmissionService calls {@see recordReview()}
 * inside the review transaction (after SM-2 and the `training_reviews` insert),
 * so whatever this method writes is committed or rolled back together with the
 * grade. Task 1.3b fills it in: `LearnerClock::today()` for the local date,
 * upsert of `(learner_id, date)` under the unique index with increments of
 * `reviews_count` / `correct_count` (`quality >= 3`) / `new_words_count`
 * (`$isNewCard`) / `time_spent_ms`, `goal_met_at` set once when `reviews_count`
 * first reaches `daily_goal` (→ StreakService::onGoalMet); the return type then
 * becomes `LearnerDailyStats` (the caller ignores the return value).
 *
 * Until then it is a no-op so personal sessions can ship first.
 *
 * Deliberately not final: tests of the review transaction replace it with a
 * double to observe the call and to fault it (rollback of the grade).
 */
class DailyStatsService
{
    /**
     * @param  bool  $isNewCard  the card was in state `new` before this grade (first answer ever)
     */
    public function recordReview(
        Learner $learner,
        int $quality,
        int $timeSpentMs,
        bool $isNewCard,
        CarbonImmutable $now,
    ): void {
        // Filled in by task 1.3b.
    }
}
