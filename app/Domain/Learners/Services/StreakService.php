<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Learners\DTO\StreakState;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily-goal streak of a learner (docs/12 §7.4).
 *
 * Source of truth is `learner_daily_stats.goal_met_at` — one fact per local
 * day, written inside the review transaction. `learners.streak_current /
 * streak_best / streak_last_date` are a denormalised projection of those facts
 * maintained by {@see apply()}:
 *
 *  - credited local day `d`: if `streak_last_date = d - 1` → `current + 1`,
 *    otherwise `current = 1`; `best = max(best, current)`; `last = d`;
 *  - a day is credited at most once, and never a day **before** the last
 *    credited one (`last >= d` → no-op). This is what keeps a timezone change
 *    harmless: moving west re-opens an already credited date (no second
 *    credit), moving east may open the next date early (one honest day earned
 *    with a full goal — the "minor anomaly" docs/12 §7.4 accepts);
 *  - reading ({@see current()}) zeroes the series lazily when
 *    `last < today - 1`; no nightly job and no write.
 *
 * Why the learners UPDATE runs **after commit** and not inside the review
 * transaction: the review transaction holds `training_sessions → word_repetitions
 * → learner_daily_stats`, while CardPicker serialises new cards on a `FOR UPDATE`
 * of the `learners` row in its own transaction. Touching `learners` from inside
 * the review transaction would add a fourth lock in the opposite order and make
 * a deadlock possible. So `onGoalMet` only registers `DB::afterCommit`; the
 * projection is updated by a single-statement optimistic UPDATE
 * (`WHERE streak_last_date = <seen>`), which holds no other lock and cannot be
 * part of a cycle. If the process dies in the tiny window between commit and
 * callback, `current()` repairs the projection from today's stats row.
 *
 * Deliberately not final: tests of DailyStatsService replace it with a double
 * to observe `onGoalMet`.
 */
class StreakService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly ProductEventRecorder $events) {}

    /**
     * Called by DailyStatsService inside the review transaction the first time
     * `reviews_count >= daily_goal` on `$localDate`. Defers the `learners`
     * projection until the grade is committed; nothing happens on rollback.
     */
    public function onGoalMet(Learner $learner, CarbonImmutable $localDate): void
    {
        $date = $localDate->toDateString();

        DB::afterCommit(function () use ($learner, $date): void {
            $this->apply($learner, $date);
        });
    }

    /**
     * Credits local day `$date` (`Y-m-d`) to the learner's streak. Idempotent;
     * returns true when the projection changed. Also refreshes the streak
     * attributes of the passed model so the current request sees the result.
     */
    public function apply(Learner $learner, string $date): bool
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            /** @var Learner|null $fresh */
            $fresh = Learner::withTrashed()->find($learner->id, ['id', 'streak_current', 'streak_best', 'streak_last_date']);
            if ($fresh === null) {
                return false;
            }

            $last = $fresh->streak_last_date?->toDateString();

            if ($last !== null && $last >= $date) {
                $this->syncModel($learner, $fresh->streak_current, $fresh->streak_best, $last);

                return false;
            }

            $continues = $last === CarbonImmutable::parse($date)->subDay()->toDateString();
            $current = $continues ? $fresh->streak_current + 1 : 1;
            $best = max($fresh->streak_best, $current);

            // Optimistic single-statement UPDATE: no FOR UPDATE, no lock ordering concerns.
            // Dates go through the model's date format so sqlite (text column) compares
            // what Eloquent stores; PostgreSQL normalises to DATE either way.
            $query = Learner::withTrashed()->whereKey($learner->id);
            $last === null
                ? $query->whereNull('streak_last_date')
                : $query->where('streak_last_date', (string) $fresh->fromDateTime($last));

            $affected = $query->update([
                'streak_current' => $current,
                'streak_best' => $best,
                'streak_last_date' => (string) $fresh->fromDateTime($date),
            ]);

            if ($affected === 1) {
                $this->syncModel($learner, $current, $best, $date);
                $this->recordEvents($learner, $date, $current, $best, $continues);

                return true;
            }
        }

        Log::warning('streak.apply_contended', ['learner_id' => $learner->id, 'date' => $date]);

        return false;
    }

    /**
     * @param  CarbonImmutable  $today  the learner's local day from LearnerClock::today()
     * @param  LearnerDailyStats|null  $todayStats  today's row when the caller already has it: lets a goal met
     *                                              today but not yet projected onto `learners` be repaired here
     */
    public function current(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $todayStats = null): StreakState
    {
        $todayDate = $today->toDateString();
        $yesterday = $today->subDay()->toDateString();
        $last = $learner->streak_last_date?->toDateString();

        if ($todayStats?->goal_met_at !== null && ($last === null || $last < $todayDate)) {
            $this->apply($learner, $todayDate);
            $last = $learner->streak_last_date?->toDateString();
        }

        // A freshly created model may not have loaded the column defaults yet.
        $metToday = $last !== null && $last >= $todayDate;
        $current = $last !== null && $last >= $yesterday ? (int) $learner->streak_current : 0;

        return new StreakState(
            current: $current,
            best: max((int) $learner->streak_best, $current),
            metToday: $metToday,
            atRisk: $current > 0 && ! $metToday,
        );
    }

    private function syncModel(Learner $learner, int $current, int $best, string $last): void
    {
        $learner->forceFill([
            'streak_current' => $current,
            'streak_best' => $best,
            'streak_last_date' => $last,
        ])->syncOriginalAttributes(['streak_current', 'streak_best', 'streak_last_date']);
    }

    private function recordEvents(Learner $learner, string $date, int $current, int $best, bool $continues): void
    {
        $this->events->record(ProductEventName::DailyGoalMet, ['streak' => $current, 'date' => $date], learner: $learner);

        if ($continues) {
            $this->events->record(ProductEventName::StreakExtended, ['current' => $current, 'best' => $best], learner: $learner);
        }
    }
}
