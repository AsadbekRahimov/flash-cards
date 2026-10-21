<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\ReferralService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Per-local-day aggregates of a learner (`learner_daily_stats`, docs/12 §1.6, §3.1, §7.4).
 *
 * {@see recordReview()} is called by ReviewSubmissionService **inside the review
 * transaction** (after SM-2 and the `training_reviews` insert), so the counters
 * are committed or rolled back together with the grade. The day is the
 * learner's local date at the moment of the grade (LearnerClock); a review at
 * 23:59 and one at 00:01 local time land in two different rows even though
 * they may share a UTC date.
 *
 * Concurrency (two tabs grading at the same instant):
 *  1. `INSERT ... ON CONFLICT DO NOTHING` makes sure the `(learner_id, date)`
 *     row exists — a loser of the race simply does nothing;
 *  2. `SELECT ... FOR UPDATE` on that row serialises the increments; the
 *     winner's row is what the second transaction reads once it unblocks;
 *  3. counters are rewritten from the locked values (no raw SQL expressions);
 *  4. `goal_met_at` is set only while it is NULL under that same lock, so the
 *     goal is "met" exactly once per day and StreakService hears about it once.
 *
 * Lock order inside the review transaction stays `training_sessions →
 * word_repetitions → learner_daily_stats`; this class never touches `learners`
 * (CardPicker holds that row in its own transaction — docs/12 §5.3).
 *
 * Deliberately not final: tests of the review transaction replace it with a
 * double to observe the call and to fault it (rollback of the grade).
 */
class DailyStatsService
{
    public function __construct(
        private readonly LearnerClock $clock,
        private readonly StreakService $streaks,
        private readonly ReferralService $referrals,
        private readonly LeagueService $leagues,
    ) {}

    /**
     * @param  bool  $isNewCard  the card was in state `new` before this grade (first answer ever)
     */
    public function recordReview(
        Learner $learner,
        int $quality,
        int $timeSpentMs,
        bool $isNewCard,
        CarbonImmutable $now,
    ): LearnerDailyStats {
        $today = $this->clock->today($learner, $now);
        $row = $this->lockedRow($learner->id, $today->toDateString(), $now);

        $reviews = $row->reviews_count + 1;
        // A fresh plain read, not `$learner->daily_goal`: the model is as old as the request and a
        // goal lowered meanwhile (PATCH /me/settings) would be missed. No lock and no write on
        // `learners`, so the lock order of docs/06 §4.2 holds.
        $goal = max(1, (int) (Learner::query()->whereKey($learner->id)->value('daily_goal') ?? $learner->daily_goal));
        $goalJustMet = $row->goal_met_at === null && $reviews >= $goal;

        $row->forceFill([
            'reviews_count' => $reviews,
            'correct_count' => $row->correct_count + ($quality >= 3 ? 1 : 0),
            'new_words_count' => $row->new_words_count + ($isNewCard ? 1 : 0),
            'time_spent_ms' => $row->time_spent_ms + max(0, $timeSpentMs),
            'goal_met_at' => $goalJustMet ? $now : $row->goal_met_at,
        ])->save();

        if ($goalJustMet) {
            $this->streaks->onGoalMet($learner, $today);
        }

        // Referral activation (docs/13 §7.1) looks at committed day rows: the moment this day crosses the
        // "active day" threshold, re-evaluate a pending referral of the learner — after commit, never inside.
        if ($reviews === max(1, (int) config('referrals.activation.reviews_per_day'))) {
            DB::afterCommit(fn () => $this->referrals->evaluate($learner, $now));
        }

        // League placement (docs/13 §7.3): the first grade of a local day is the cheapest moment to
        // make sure the learner sits in this week's group (an indexed lookup; a no-op once placed).
        // After commit, never inside — and never a failed grade: a league error is only reported.
        if ($reviews === 1) {
            DB::afterCommit(function () use ($learner, $now): void {
                try {
                    $this->leagues->place($learner, $now);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $row;
    }

    /**
     * Completes today's goal when the daily goal was lowered to (or below) what is already done:
     * sets `goal_met_at` under the lock of today's row and lets {@see StreakService} credit the day
     * after commit. Does nothing when there is no row yet, the goal is already met, or it is not reached.
     * A goal raised again later does not take `goal_met_at` back — a day is met once (docs/12 §7.4).
     *
     * Runs in its own transaction and locks only `learner_daily_stats`: call it with `$learner->daily_goal`
     * already saved and no lock on `learners` held (LearnerSettingsService does it after its commit).
     *
     * @return bool the goal was completed by this call
     */
    public function reconcileGoal(Learner $learner, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $today = $this->clock->today($learner, $now);
        $met = false;

        DB::transaction(function () use ($learner, $now, $today, &$met): void {
            /** @var LearnerDailyStats|null $row */
            $row = LearnerDailyStats::query()
                ->where('learner_id', $learner->id)
                ->where('date', $this->storedDate($today->toDateString()))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->goal_met_at !== null || $row->reviews_count < max(1, $learner->daily_goal)) {
                return;
            }

            $row->forceFill(['goal_met_at' => $now])->save();
            $this->streaks->onGoalMet($learner, $today);
            $met = true;
        });

        return $met;
    }

    /** The learner's row for the current local day, or null when nothing was graded yet. */
    public function today(Learner $learner, ?CarbonImmutable $now = null): ?LearnerDailyStats
    {
        return $this->forDate($learner, $this->clock->localDate($learner, $now));
    }

    /** @param  string  $localDate  `Y-m-d` */
    public function forDate(Learner $learner, string $localDate): ?LearnerDailyStats
    {
        /** @var LearnerDailyStats|null $row */
        $row = LearnerDailyStats::query()
            ->where('learner_id', $learner->id)
            ->where('date', $this->storedDate($localDate))
            ->first();

        return $row;
    }

    private function lockedRow(int $learnerId, string $date, CarbonImmutable $now): LearnerDailyStats
    {
        $stored = $this->storedDate($date);

        LearnerDailyStats::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'date' => $stored,
            'reviews_count' => 0,
            'new_words_count' => 0,
            'correct_count' => 0,
            'time_spent_ms' => 0,
            'goal_met_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var LearnerDailyStats|null $row */
        $row = LearnerDailyStats::query()
            ->where('learner_id', $learnerId)
            ->where('date', $stored)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new RuntimeException("learner_daily_stats row for learner {$learnerId} on {$date} vanished after upsert.");
        }

        return $row;
    }

    /**
     * The `date` column exactly as Eloquent stores a `date` cast (`Y-m-d 00:00:00`
     * via the connection's date format). PostgreSQL normalises it to DATE either
     * way; on sqlite the column is text, so every write and comparison must use
     * one representation or the unique index would not see duplicates.
     */
    private function storedDate(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
    }
}
