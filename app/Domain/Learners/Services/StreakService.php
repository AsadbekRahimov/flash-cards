<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Learners\DTO\StreakState;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily-goal streak of a learner (docs/12 §7.4, freezes: docs/06 §6a, docs/13 §7.2).
 *
 * Source of truth is `learner_daily_stats`: a local day is **held** when `goal_met_at` is set
 * (written inside the review transaction) OR `frozen_at` is set (a streak freeze spent on a
 * missed day, StreakFreezeService). `learners.streak_current / streak_best / streak_last_date`
 * are a denormalised projection of those facts maintained by {@see project()}:
 *
 *  - held local day `d`: if `streak_last_date = d - 1` → `current + 1` for a met day, unchanged
 *    for a frozen one; otherwise (a gap, or no credit yet) the series is **rebuilt from the
 *    facts**: `run` = the goal-met days of the unbroken series of held days ending on `d - 1`
 *    (frozen days bridge it without counting; at most {@see MAX_REBUILD_DAYS} deep),
 *    `current = run + 1` for a met day (so a day whose callback was lost, or arrived after the
 *    next day's, is not lost: with nothing held on `d - 1` this is the plain restart `current = 1`);
 *    `best = max(best, current)`; `last = d`;
 *  - a day is held at most once, and never a day **before** the last held one (`last >= d` →
 *    no-op). This is what keeps a timezone change harmless: moving west re-opens an already held
 *    date (no second credit), moving east may open the next date early (one honest day earned
 *    with a full goal — the "minor anomaly" docs/12 §7.4 accepts);
 *  - **freeze**: when the last held day is exactly `today - 2` (yesterday was missed and nothing
 *    else), a Pro learner with a freeze in stock gets yesterday frozen automatically — on read
 *    ({@see current()}) or when today's goal is met ({@see apply()}) — and the series goes on;
 *    a gap of two or more days is never frozen. The inventory and the fact are
 *    StreakFreezeService's; the projection then advances `last` to the frozen day;
 *  - reading ({@see current()}) zeroes the series lazily when `last < today - 1` and yesterday
 *    is neither held nor freezable; no nightly job. If yesterday is held (its callback was lost),
 *    `current()` projects it first — so it is **not** a pure read: it may issue the same
 *    single-statement `UPDATE learners` as {@see apply()} (and the freeze transaction) and must
 *    not be called inside a transaction holding `training_sessions` / `word_repetitions` /
 *    `learner_daily_stats`.
 *
 * Why the learners UPDATE runs **after commit** and not inside the review
 * transaction: the review transaction holds `training_sessions → word_repetitions
 * → learner_daily_stats`, while CardPicker serialises new cards on a `FOR UPDATE`
 * of the `learners` row in its own transaction. Touching `learners` from inside
 * the review transaction would add a fourth lock in the opposite order and make
 * a deadlock possible. So `onGoalMet` only registers `DB::afterCommit`; the
 * projection is updated by a single-statement optimistic UPDATE
 * (`WHERE streak_last_date = <seen>`), which holds no other lock and cannot be
 * part of a cycle. The freeze, for the same reason, is spent in that callback or on read —
 * never inside the review transaction. If the process dies in the tiny window between commit
 * and callback, `current()` repairs the projection from the facts (today's stats row, or
 * yesterday's fact).
 *
 * Deliberately not final: tests of DailyStatsService replace it with a double
 * to observe `onGoalMet`.
 */
class StreakService
{
    private const MAX_ATTEMPTS = 3;

    /** How many days back a series is rebuilt from `learner_daily_stats` when the projection has a gap. */
    private const MAX_REBUILD_DAYS = 400;

    public function __construct(
        private readonly ProductEventRecorder $events,
        private readonly StreakFreezeService $freezes,
    ) {}

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
     * Credits local day `$date` (`Y-m-d`), on which the goal was met, to the learner's streak.
     * Idempotent; returns true when the projection changed. Also refreshes the streak attributes
     * of the passed model so the current request sees the result.
     *
     * Not a pure projection step: when `streak_last_date` is not `$date - 1` the series is
     * rebuilt from `learner_daily_stats` — after an attempt to freeze `$date - 1` if exactly that
     * one day was missed (see the class docblock).
     */
    public function apply(Learner $learner, string $date, ?CarbonImmutable $now = null): bool
    {
        return $this->project($learner, $date, true, $now ?? CarbonImmutable::now());
    }

    /**
     * Not a pure read — may run {@see apply()} (one `UPDATE learners`) and spend a streak freeze;
     * never call it inside a transaction that holds `training_sessions` / `word_repetitions` /
     * `learner_daily_stats`.
     *
     * @param  CarbonImmutable  $today  the learner's local day from LearnerClock::today()
     * @param  LearnerDailyStats|null  $todayStats  today's row when the caller already has it: lets a goal met
     *                                              today but not yet projected onto `learners` be repaired here
     * @param  CarbonImmutable|null  $now  the instant (entitlement, `frozen_at`); defaults to the real clock
     */
    public function current(Learner $learner, CarbonImmutable $today, ?LearnerDailyStats $todayStats = null, ?CarbonImmutable $now = null): StreakState
    {
        $now ??= CarbonImmutable::now();
        $todayDate = $today->toDateString();
        $yesterday = $today->subDay()->toDateString();
        $dayBefore = $today->subDays(2)->toDateString();
        $last = $learner->streak_last_date?->toDateString();

        $yesterdayRow = $this->heldRow($learner->id, $yesterday);
        $frozenYesterday = $yesterdayRow?->frozen_at !== null;
        $freezesAvailable = $this->freezes->available($learner, $now);

        if ($todayStats?->goal_met_at !== null && ($last === null || $last < $todayDate)) {
            $this->apply($learner, $todayDate, $now);
            if ($yesterdayRow === null && $freezesAvailable > 0) {
                $frozenYesterday = $this->heldRow($learner->id, $yesterday)?->frozen_at !== null; // apply() may have frozen it
            }
        } elseif ($last === null || $last < $yesterday) {
            if ($yesterdayRow !== null) {
                // The projection missed yesterday (callback lost) — without this fact the series would read as 0.
                $this->project($learner, $yesterday, $yesterdayRow->goal_met_at !== null, $now);
            } elseif ($last !== null && $freezesAvailable > 0 && $this->freezeYesterday($learner, $yesterday, $dayBefore, $last, $now)) {
                $frozenYesterday = true;
            }
        }

        if ($frozenYesterday && $freezesAvailable > 0) {
            // The freeze that holds yesterday may have been spent by a concurrent request after this model was loaded.
            $this->freezes->refresh($learner);
        }

        $last = $learner->streak_last_date?->toDateString();

        // A freshly created model may not have loaded the column defaults yet.
        $metToday = $last !== null && $last >= $todayDate;
        $current = $last !== null && $last >= $yesterday ? (int) $learner->streak_current : 0;

        return new StreakState(
            current: $current,
            best: max((int) $learner->streak_best, $current),
            metToday: $metToday,
            atRisk: $current > 0 && ! $metToday,
            freezes: $this->freezes->available($learner, $now),
            frozenYesterday: $frozenYesterday,
        );
    }

    /**
     * On read: yesterday is not held and the projection ends before it. Freezes yesterday when the
     * last held day is exactly the day before — by the projection, or by the facts when the
     * projection lags behind them (that day's callback was lost: it is projected first).
     */
    private function freezeYesterday(Learner $learner, string $yesterday, string $dayBefore, string $last, CarbonImmutable $now): bool
    {
        if ($last < $dayBefore) {
            $row = $this->heldRow($learner->id, $dayBefore);
            if ($row === null) {
                return false; // two or more days missed: nothing to freeze
            }
            $this->project($learner, $dayBefore, $row->goal_met_at !== null, $now);
            if ($learner->streak_last_date?->toDateString() !== $dayBefore) {
                return false;
            }
        }

        if (! $this->freezes->tryFreeze($learner, $yesterday, $now)) {
            // A concurrent reader may have frozen it a moment ago: then it is a fact like any other.
            $row = $this->heldRow($learner->id, $yesterday);
            if ($row === null) {
                return false;
            }
            $this->project($learner, $yesterday, $row->goal_met_at !== null, $now);

            return $row->frozen_at !== null;
        }

        $this->project($learner, $yesterday, false, $now);

        return true;
    }

    /**
     * Projects held local day `$date` onto `learners` (`$goalMet`: the goal was met on it; otherwise it
     * was frozen). Idempotent; returns true when the projection changed.
     */
    private function project(Learner $learner, string $date, bool $goalMet, CarbonImmutable $now): bool
    {
        $freezeTried = false;

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

            $previous = CarbonImmutable::parse($date)->subDay()->toDateString();
            $bridged = false;

            if ($goalMet && $last !== null && $last !== $previous && ! $freezeTried) {
                // Goal met today after exactly one missed day (yesterday): a freeze keeps the series.
                $freezeTried = true;
                $bridged = $this->freezeGapBefore($learner, $previous, $last, $now);
            }

            $credit = $goalMet ? 1 : 0;
            $current = $last === $previous || $bridged
                ? $fresh->streak_current + $credit
                : $this->factRunEndingOn($learner->id, $previous) + $credit;
            $continues = $goalMet && $current > 1;
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
                if ($goalMet) {
                    $this->recordEvents($learner, $date, $current, $best, $continues);
                }

                return true;
            }
        }

        Log::warning('streak.apply_contended', ['learner_id' => $learner->id, 'date' => $date]);

        return false;
    }

    /**
     * Before a met day `d` is projected with a gap: freezes `d - 1` when it is not held and the
     * last held day is `d - 2` (by the projection, or by the facts when the projection lags).
     * Returns true when the projection's own series (`last = d - 2`) now continues through a held
     * `d - 1` — frozen here or a moment earlier by a concurrent caller — so the caller counts on
     * from `streak_current` instead of rebuilding (a projection older than the facts' retention
     * has nothing to rebuild from). With a lagging projection the rebuild that follows sees the
     * frozen day as a bridge.
     */
    private function freezeGapBefore(Learner $learner, string $previous, string $last, CarbonImmutable $now): bool
    {
        $dayBefore = CarbonImmutable::parse($previous)->subDay()->toDateString();

        if ($last !== $dayBefore && ($last > $dayBefore || $this->heldRow($learner->id, $dayBefore) === null)) {
            return false;
        }

        $row = $this->heldRow($learner->id, $previous);
        if ($row === null) {
            if ($this->freezes->tryFreeze($learner, $previous, $now)) {
                return $last === $dayBefore;
            }
            $row = $this->heldRow($learner->id, $previous); // a concurrent caller may have frozen it a moment ago
        }

        // A met `d - 1` (its callback was lost) must be counted by the rebuild; only a frozen one bridges.
        return $row !== null && $row->goal_met_at === null && $last === $dayBefore;
    }

    /** The row of a held local day (goal met or frozen), or null when the day is not held. */
    private function heldRow(int $learnerId, string $date): ?LearnerDailyStats
    {
        /** @var LearnerDailyStats|null $row */
        $row = LearnerDailyStats::query()
            ->where('learner_id', $learnerId)
            ->where('date', $this->storedDate($date))
            ->where(fn ($q) => $q->whereNotNull('goal_met_at')->orWhereNotNull('frozen_at'))
            ->first(['id', 'learner_id', 'date', 'goal_met_at', 'frozen_at']);

        return $row;
    }

    /**
     * Goal-met days of the unbroken series of held local days (`goal_met_at` or `frozen_at`) that ends on
     * `$endDate` (0 when `$endDate` itself is not held). Frozen days keep the series unbroken but are not
     * counted. One query, at most MAX_REBUILD_DAYS rows.
     */
    private function factRunEndingOn(int $learnerId, string $endDate): int
    {
        $end = CarbonImmutable::parse($endDate);

        $rows = LearnerDailyStats::query()
            ->where('learner_id', $learnerId)
            ->where(fn ($q) => $q->whereNotNull('goal_met_at')->orWhereNotNull('frozen_at'))
            ->where('date', '<=', $this->storedDate($endDate))
            ->where('date', '>', $this->storedDate($end->subDays(self::MAX_REBUILD_DAYS)->toDateString()))
            ->orderByDesc('date')
            ->get(['date', 'goal_met_at']);

        $run = 0;
        $offset = 0;
        foreach ($rows as $row) {
            if ($row->date->toDateString() !== $end->subDays($offset)->toDateString()) {
                break;
            }
            $offset++;
            if ($row->goal_met_at !== null) {
                $run++;
            }
        }

        return $run;
    }

    /** The `date` column as Eloquent stores it (see DailyStatsService::storedDate): sqlite compares text. */
    private function storedDate(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
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
