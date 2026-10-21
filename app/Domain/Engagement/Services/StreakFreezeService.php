<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Engagement\Exceptions\FreezeNotApplied;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Streak freeze (docs/13 §2.4, §7.2; docs/06 §6a): the inventory `learners.streak_freezes` and
 * the fact `learner_daily_stats.frozen_at` ("this local day was held by a freeze").
 *
 * Only a learner whose plan has the feature {@see FEATURE} (Pro, own or sponsored) can spend or
 * receive freezes; the inventory of a learner whose Pro lapsed is kept but idle. StreakService
 * decides WHEN a day is to be frozen (exactly one local day missed) and treats a frozen day as
 * held; this class only owns the inventory and the fact.
 *
 * {@see tryFreeze()} — one short transaction of its own, never inside the review transaction
 * (StreakService runs it after commit or on read): `UPDATE learners SET streak_freezes - 1 WHERE
 * streak_freezes > 0` (takes the learner row, the same first lock the card picker takes — lock
 * order of docs/06 §4.2 and docs/13 §3 is kept: learners → learner_daily_stats), then the claim of
 * the date: the `(learner_id, date)` row is created if missing and `frozen_at` is set only while
 * `frozen_at IS NULL AND goal_met_at IS NULL`. A claim that affects no row (the date is already
 * frozen or was actually met) rolls the decrement back. So two concurrent callers for the same
 * date spend exactly one freeze, and a freeze is never spent without its fact.
 *
 * {@see refill()} — `+1` once per local week (Monday-based, `startOfWeek` of the learner's local
 * day) up to `config('learners.streak_freeze.max')`, lazily on the Today screen. The week is
 * recorded as its Monday in `streak_freezes_refilled_on` even when the inventory was already
 * full, and only a later Monday qualifies again — so neither repeated reads nor a timezone change
 * that moves "today" by a day can hand out a second refill for the same week.
 */
final class StreakFreezeService
{
    public const FEATURE = 'streak.freeze';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly ProductEventRecorder $events,
    ) {}

    /** The learner's plan includes freezes (Pro feature); config max 0 switches them off for everyone. */
    public function enabled(Learner $learner, CarbonImmutable $now): bool
    {
        return $this->max() > 0 && $this->entitlements->for($learner, $now)->can(self::FEATURE);
    }

    /** Freezes the learner can spend right now: 0 without the feature, whatever is stored otherwise. */
    public function available(Learner $learner, CarbonImmutable $now): int
    {
        return $this->enabled($learner, $now) ? max(0, (int) $learner->streak_freezes) : 0;
    }

    /** Re-reads the stored inventory onto the model (a concurrent request may have spent or refilled it). */
    public function refresh(Learner $learner): void
    {
        $this->syncFreezes($learner, (int) (Learner::withTrashed()->whereKey($learner->id)->value('streak_freezes') ?? 0));
    }

    public function max(): int
    {
        return max(0, (int) config('learners.streak_freeze.max', 2));
    }

    /**
     * Spends one freeze on the missed local day `$missedLocalDate` (`Y-m-d`). Returns true when THIS
     * call froze the day; false when the learner has no feature / no freezes, the day is already
     * frozen, or the goal was actually met on it. Refreshes `streak_freezes` on the passed model.
     *
     * Must not be called inside a transaction that holds `training_sessions` / `word_repetitions` /
     * `learner_daily_stats` (it takes `learners` first — docs/06 §4.2).
     */
    public function tryFreeze(Learner $learner, string $missedLocalDate, CarbonImmutable $now): bool
    {
        return $this->tryFreezeMany($learner, [$missedLocalDate], $now);
    }

    /**
     * All-or-nothing: spends one freeze per missed local day (`Y-m-d`) of `$missedLocalDates` in ONE
     * transaction — N missed days need N freezes in stock, otherwise nothing is spent and the series
     * is left to burn (owner's rule, docs/13 §2.4 "2C.6"). Returns true when THIS call froze every
     * date; false when the learner has no feature / not enough freezes, or any date is already frozen
     * or was actually met (everything is rolled back, so a retry or a concurrent caller never
     * double-spends). Same lock order and constraints as {@see tryFreeze()}.
     *
     * @param  list<string>  $missedLocalDates
     */
    public function tryFreezeMany(Learner $learner, array $missedLocalDates, CarbonImmutable $now): bool
    {
        $count = count($missedLocalDates);

        if ($count === 0 || ! $this->enabled($learner, $now)) {
            return false;
        }

        // The passed model may be older than the request; a cheap read avoids a pointless transaction.
        $stored = (int) (Learner::withTrashed()->whereKey($learner->id)->value('streak_freezes') ?? 0);
        $this->syncFreezes($learner, $stored);
        if ($stored < $count) {
            return false;
        }

        $left = null;

        try {
            DB::transaction(function () use ($learner, $missedLocalDates, $count, $now, &$left): void {
                $spent = Learner::withTrashed()
                    ->whereKey($learner->id)
                    ->where('streak_freezes', '>=', $count)
                    ->decrement('streak_freezes', $count);

                if ($spent !== 1) {
                    throw new FreezeNotApplied;
                }

                foreach ($missedLocalDates as $missedLocalDate) {
                    $date = $this->storedDate($missedLocalDate);

                    LearnerDailyStats::query()->insertOrIgnore([
                        'learner_id' => $learner->id,
                        'date' => $date,
                        'reviews_count' => 0,
                        'new_words_count' => 0,
                        'correct_count' => 0,
                        'time_spent_ms' => 0,
                        'goal_met_at' => null,
                        'frozen_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $claimed = LearnerDailyStats::query()
                        ->where('learner_id', $learner->id)
                        ->where('date', $date)
                        ->whereNull('frozen_at')
                        ->whereNull('goal_met_at')
                        ->update(['frozen_at' => $now, 'updated_at' => $now]);

                    if ($claimed !== 1) {
                        throw new FreezeNotApplied; // already frozen (a concurrent caller) or actually met: give the freezes back
                    }
                }

                $left = (int) (Learner::withTrashed()->whereKey($learner->id)->value('streak_freezes') ?? 0);
            });
        } catch (FreezeNotApplied) {
            $this->refresh($learner); // a concurrent caller may have frozen the same date: show what is stored now

            return false;
        }

        $this->syncFreezes($learner, (int) $left);

        $remaining = (int) $left;
        foreach ($missedLocalDates as $index => $missedLocalDate) {
            $this->events->record(ProductEventName::StreakFrozen, ['date' => $missedLocalDate, 'freezes_left' => $remaining + $count - $index - 1], learner: $learner);
        }

        return true;
    }

    /**
     * Adds one freeze for the local week of `$today` (the learner's local midnight from
     * LearnerClock::today()) unless that week was already handled. Returns true when a freeze was
     * added. Refreshes the passed model. A single conditional statement per outcome — no locks held
     * across statements, safe to call on every read.
     */
    public function refill(Learner $learner, CarbonImmutable $today, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (! $this->enabled($learner, $now)) {
            return false;
        }

        $weekStart = $today->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $storedWeek = (string) $learner->fromDateTime($weekStart);
        $max = $this->max();

        $added = Learner::withTrashed()
            ->whereKey($learner->id)
            ->where(fn ($q) => $q->whereNull('streak_freezes_refilled_on')->orWhere('streak_freezes_refilled_on', '<', $storedWeek))
            ->where('streak_freezes', '<', $max)
            ->increment('streak_freezes', 1, ['streak_freezes_refilled_on' => $storedWeek]);

        if ($added === 1) {
            $learner->forceFill([
                'streak_freezes' => (int) (Learner::withTrashed()->whereKey($learner->id)->value('streak_freezes') ?? 0),
                'streak_freezes_refilled_on' => $weekStart,
            ])->syncOriginalAttributes(['streak_freezes', 'streak_freezes_refilled_on']);

            return true;
        }

        // Full inventory: the week still counts as handled, so a freeze spent on Tuesday is not refilled on Wednesday.
        $marked = Learner::withTrashed()
            ->whereKey($learner->id)
            ->where(fn ($q) => $q->whereNull('streak_freezes_refilled_on')->orWhere('streak_freezes_refilled_on', '<', $storedWeek))
            ->where('streak_freezes', '>=', $max)
            ->update(['streak_freezes_refilled_on' => $storedWeek]);

        if ($marked === 1) {
            $learner->forceFill(['streak_freezes_refilled_on' => $weekStart])->syncOriginalAttributes(['streak_freezes_refilled_on']);
        }

        return false;
    }

    private function syncFreezes(Learner $learner, int $freezes): void
    {
        $learner->forceFill(['streak_freezes' => $freezes])->syncOriginalAttributes(['streak_freezes']);
    }

    /** The `date` column as Eloquent stores it (see DailyStatsService::storedDate): sqlite compares text. */
    private function storedDate(string $localDate): string
    {
        return (string) (new LearnerDailyStats)->fromDateTime($localDate);
    }
}
