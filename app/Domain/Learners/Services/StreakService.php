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
use Illuminate\Database\Eloquent\Collection;
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
            } elseif ($last !== null && $freezesAvailable > 0 && $this->freezeMissed($learner, $yesterday, $last, $now)) {
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
     * On read: yesterday is not held and the projection ends before it. First brings the projection up to
     * the facts (held days the callbacks of which were lost), then freezes the whole run of missed days up
     * to yesterday — one freeze each, all or none ({@see unheldTail()}) — and projects them. Returns true
     * when yesterday ends up frozen (by this call or a concurrent one).
     */
    private function freezeMissed(Learner $learner, string $yesterday, string $last, CarbonImmutable $now): bool
    {
        $this->projectHeldRows($learner, $last, $yesterday, $now);

        $last = $learner->streak_last_date?->toDateString();
        if ($last === null) {
            return false;
        }

        $tail = $this->unheldTail($learner->id, $last, $yesterday);
        if ($tail === null || $tail === []) {
            return false; // more missed days than freezes can ever cover (or nothing missed): the series burns
        }

        $this->freezes->tryFreezeMany($learner, $tail, $now); // false: too few freezes, or a concurrent caller got there first

        // Whatever is held now (our freezes, or a concurrent caller's) is a fact like any other.
        $this->projectHeldRows($learner, $last, $yesterday, $now);

        return $this->heldRow($learner->id, $yesterday)?->frozen_at !== null;
    }

    /**
     * Projects the held days (goal met or frozen) after `$last` up to `$through`, oldest first. Looks back
     * at most as far as freezes can reach (the older gap cannot be bridged anyway).
     */
    private function projectHeldRows(Learner $learner, string $last, string $through, CarbonImmutable $now): void
    {
        $from = $this->windowStart($last, $through);

        foreach ($this->heldRowsBetween($learner->id, $from, $through) as $row) {
            $this->project($learner, $row->date->toDateString(), $row->goal_met_at !== null, $now);
        }
    }

    /**
     * The run of days that are NOT held and end on `$through`, oldest first, when it can be covered:
     * it starts right after the last held day (`$last` by the projection, or a held fact above it) and is
     * no longer than the freeze maximum. `[]` — `$through` itself is held or beyond `$last`; `null` — the
     * run is longer than any inventory can cover, so there is nothing to freeze.
     *
     * @return list<string>|null
     */
    private function unheldTail(int $learnerId, string $last, string $through): ?array
    {
        if ($last >= $through) {
            return [];
        }

        $from = $this->windowStart($last, $through);
        $held = [];
        foreach ($this->heldRowsBetween($learnerId, $from, $through) as $row) {
            $held[$row->date->toDateString()] = true;
        }

        $tail = [];
        for ($day = CarbonImmutable::parse($through); $day->toDateString() >= $from; $day = $day->subDay()) {
            if (isset($held[$day->toDateString()])) {
                break;
            }
            array_unshift($tail, $day->toDateString());
        }

        return count($tail) > $this->freezes->max() ? null : $tail;
    }

    /** First day worth looking at: the day after `$last`, but not farther back than the freeze maximum reaches. */
    private function windowStart(string $last, string $through): string
    {
        $reach = CarbonImmutable::parse($through)->subDays($this->freezes->max())->toDateString();
        $afterLast = CarbonImmutable::parse($last)->addDay()->toDateString();

        return max($afterLast, $reach);
    }

    /**
     * Held rows (goal met or frozen) with `$from <= date <= $through`, oldest first.
     *
     * @return Collection<int, LearnerDailyStats>
     */
    private function heldRowsBetween(int $learnerId, string $from, string $through): Collection
    {
        return LearnerDailyStats::query()
            ->where('learner_id', $learnerId)
            ->where(fn ($q) => $q->whereNotNull('goal_met_at')->orWhereNotNull('frozen_at'))
            ->where('date', '>=', $this->storedDate($from))
            ->where('date', '<=', $this->storedDate($through))
            ->orderBy('date')
            ->get(['id', 'learner_id', 'date', 'goal_met_at', 'frozen_at']);
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
     * Before a met day `d` is projected with a gap: freezes the days missed since the last held day
     * (`$previous` = `d - 1` and as many days before it as are unheld), one freeze each, all or none —
     * with too few freezes nothing is spent and the series restarts. Returns true when the projection's
     * own series (`last`) now continues through held days that are ALL frozen — by us or a moment earlier
     * by a concurrent caller — so the caller counts on from `streak_current` instead of rebuilding (a
     * projection older than the facts' retention has nothing to rebuild from). With a lagging projection
     * the rebuild that follows sees the frozen days as bridges.
     */
    private function freezeGapBefore(Learner $learner, string $previous, string $last, CarbonImmutable $now): bool
    {
        $gap = (int) CarbonImmutable::parse($last)->diffInDays(CarbonImmutable::parse($previous));
        if ($gap < 1 || $gap > self::MAX_REBUILD_DAYS) {
            return false;
        }

        $tail = $this->unheldTail($learner->id, $last, $previous);
        if ($tail === null) {
            return false;
        }

        if ($tail !== []) {
            $this->freezes->tryFreezeMany($learner, $tail, $now); // false: too few freezes, or a concurrent caller froze them a moment ago
        }

        // A met day (its callback was lost) must be counted by the rebuild; only frozen days bridge.
        $rows = $this->heldRowsBetween($learner->id, CarbonImmutable::parse($last)->addDay()->toDateString(), $previous);

        return $rows->count() === $gap && $rows->every(fn (LearnerDailyStats $row): bool => $row->goal_met_at === null);
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
