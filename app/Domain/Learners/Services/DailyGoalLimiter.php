<?php

declare(strict_types=1);

namespace App\Domain\Learners\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\DTO\DailyGoalChange;
use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The daily goal against the plan (docs/34 §2б.1–2б.3; owner's decision docs/31 §5г): on Free the goal is at most
 * `billing.plans.free.limits.daily_goal_max` (10); 20 and 40 are Pro. When Pro ends - whatever ends it: the trial, a paid
 * period, a seat of a center, a refund, a revoked grant, or just the clock - the goal comes down to the limit by itself, and the
 * goal the learner had chosen comes back when Pro does.
 *
 * Written lazily where the plan is resolved anyway (`/today`, `/me`, the start of the daily round) and by the nightly
 * `learners:enforce-goal-limits` for those who do not open the Mini App (lowering only). One place writes it: here.
 *
 * {@see enforce()}:
 *  1. compares the goal with the limit IN MEMORY - with nothing to change it returns without a single statement (the usual case,
 *     so the query budgets of `/today` and `/me` do not move);
 *  2. otherwise locks the learner row (`FOR NO KEY UPDATE` on PostgreSQL, as the settings and the card picker do) and compares again
 *     on fresh data - two parallel calls: the second one finds nothing to do;
 *     - lowering: `daily_goal = cap`, `daily_goal_before_limit = <the goal before>` - only when the column is empty, so a second
 *       lowering (the limit went down again) does not overwrite the learner's own choice;
 *     - restoring (no limit any more, a remembered goal): `daily_goal = <remembered>` (or the nearest lower option when the list
 *       changed meanwhile), the column cleared;
 *  3. after that commit, never inside it: a lowering completes today's goal when what is done already reaches it
 *     ({@see DailyStatsService::reconcileGoal()}, its own transaction on `learner_daily_stats` only) - the lock order of docs/06 §4.2:
 *     `learners` and `learner_daily_stats` are never held together. A failed reconcile is reported, not thrown (as in
 *     LearnerSettingsService::update): the next grade reads the fresh goal and completes the day itself;
 *  4. product events `daily_goal.limited {from, to, via}` / `daily_goal.restored {from, to, via}`.
 *
 * A goal chosen by hand (LearnerSettingsService::update, any accepted `daily_goal`) clears `daily_goal_before_limit`: the learner's
 * choice beats the automation. Call it outside any transaction of the caller.
 */
final class DailyGoalLimiter
{
    public const VIA_TODAY = 'today';

    public const VIA_SETTINGS = 'settings';

    public const VIA_TRAINING = 'training';

    public const VIA_COMMAND = 'command';

    private const SWEEP_CHUNK = 500;

    public function __construct(
        private readonly DailyStatsService $stats,
        private readonly ProductEventRecorder $events,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * The largest goal the plan allows, as one of the offered values: the largest option not above the plan's limit (a limit that is
     * not in the list must not create a goal the settings would refuse); the limit itself when every option is above it. Null = no limit.
     */
    public function cap(Entitlement $e): ?int
    {
        return self::fit($e->dailyGoalMax);
    }

    /** The limit as one of the offered values (see {@see cap()}). */
    private static function fit(?int $max): ?int
    {
        if ($max === null) {
            return null;
        }

        $fit = null;
        foreach (self::goalOptions() as $option) {
            if ($option <= $max && ($fit === null || $option > $fit)) {
                $fit = $option;
            }
        }

        return $fit ?? $max;
    }

    /**
     * Every offered goal with whether the plan locks it (the settings screen shows a lock and opens the paywall `daily_goal`).
     *
     * @return list<array{value: int, locked: bool}>
     */
    public function options(Entitlement $e): array
    {
        $cap = $this->cap($e);

        return array_map(
            static fn (int $value): array => ['value' => $value, 'locked' => $cap !== null && $value > $cap],
            self::goalOptions(),
        );
    }

    /** @throws DailyGoalLimitException 403 `daily_goal_pro_required` when the goal is above the limit of the plan */
    public function assertAllowed(int $goal, Entitlement $e): void
    {
        $cap = $this->cap($e);

        if ($cap !== null && $goal > $cap) {
            throw new DailyGoalLimitException($goal, $cap);
        }
    }

    /**
     * Brings the stored goal in line with the plan (see the class docblock). The passed model is updated to what is stored
     * (`daily_goal`, `daily_goal_before_limit`), so the caller's screen shows the new goal.
     *
     * @param  string  $via  `today` | `settings` | `training` | `command` - for the product event
     * @return DailyGoalChange|null null = nothing was changed
     */
    public function enforce(Learner $learner, Entitlement $e, string $via, ?CarbonImmutable $now = null): ?DailyGoalChange
    {
        $now ??= CarbonImmutable::now();

        // A snapshot whose plan has run out by the clock says nothing about now (the caller resolved it a moment ago, so this is
        // a guard, not a path): the next resolve decides.
        if (! $e->isCurrentAt($now)) {
            return null;
        }

        $cap = $this->cap($e);

        // 1. In memory: no statement in the usual case.
        if ($this->plan((int) $learner->daily_goal, $learner->daily_goal_before_limit, $cap) === null) {
            return null;
        }

        // 2. Under the row lock, on fresh data.
        /** @var array{change: DailyGoalChange|null, goal: int, before: int|null}|null $outcome */
        $outcome = DB::transaction(function () use ($learner, $cap): ?array {
            $lock = Learner::query()->whereKey($learner->id);
            /** @var Learner|null $fresh */
            $fresh = (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())
                ->first(['id', 'daily_goal', 'daily_goal_before_limit']);
            if ($fresh === null) {
                return null; // deleted meanwhile
            }

            $goal = (int) $fresh->daily_goal;
            $before = $fresh->daily_goal_before_limit;
            $plan = $this->plan($goal, $before, $cap);
            if ($plan === null) {
                // Another request got here first (or the learner chose a goal meanwhile): only the model is brought up to date.
                return ['change' => null, 'goal' => $goal, 'before' => $before];
            }

            Learner::query()->whereKey($fresh->id)->update([
                'daily_goal' => $plan['to'],
                'daily_goal_before_limit' => $plan['before'],
            ]);

            return ['change' => new DailyGoalChange($plan['kind'], $goal, $plan['to']), 'goal' => $plan['to'], 'before' => $plan['before']];
        });

        if ($outcome === null) {
            return null;
        }

        $learner->forceFill(['daily_goal' => $outcome['goal'], 'daily_goal_before_limit' => $outcome['before']])
            ->syncOriginalAttributes(['daily_goal', 'daily_goal_before_limit']);

        $change = $outcome['change'];
        if ($change === null) {
            return null;
        }

        // 3. After the commit of `learners`: today's goal may be complete now. Its own transaction, `learner_daily_stats` only.
        $goalMet = false;
        if ($change->isLimited()) {
            try {
                $goalMet = $this->stats->reconcileGoal($learner, $now);
            } catch (Throwable $error) {
                report($error);
            }
        }

        // 4. The events (the recorder never throws).
        $this->events->record(
            $change->isLimited() ? ProductEventName::DailyGoalLimited : ProductEventName::DailyGoalRestored,
            ['from' => $change->from, 'to' => $change->to, 'via' => $via],
            learner: $learner,
        );

        return new DailyGoalChange($change->kind, $change->from, $change->to, $goalMet);
    }

    /**
     * The nightly sweep (`learners:enforce-goal-limits`): every learner whose goal is above the smallest limit of any plan, in chunks by
     * id, gets their plan resolved (the entitlement cache first) and {@see enforce()} - which LOWERS a goal above the learner's own
     * limit and leaves the rest alone (a Pro learner with 40 costs one cached read). Restoring is not done here: `restored` needs
     * `daily_goal <= limit`, which this selection does not look at, and it happens on the learner's next screen anyway.
     *
     * @return array{checked: int, lowered: int}
     */
    public function enforceAll(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $limits = array_filter(array_map(static fn (Plan $plan): ?int => self::fit($plan->dailyGoalMax()), Plan::cases()), static fn (?int $l): bool => $l !== null);
        if ($limits === []) {
            return ['checked' => 0, 'lowered' => 0];
        }

        $checked = 0;
        $lowered = 0;
        Learner::query()
            ->where('daily_goal', '>', min($limits))
            ->chunkById(self::SWEEP_CHUNK, function ($learners) use ($now, &$checked, &$lowered): void {
                /** @var iterable<Learner> $learners */
                foreach ($learners as $learner) {
                    $checked++;
                    try {
                        $change = $this->enforce($learner, $this->entitlements->for($learner, $now), self::VIA_COMMAND, $now);
                    } catch (Throwable $e) {
                        // One learner (a lock timeout, a broken row) must not stop the sweep; the next night tries again.
                        report($e);

                        continue;
                    }
                    if ($change?->isLimited() === true) {
                        $lowered++;
                    }
                }
            });

        return ['checked' => $checked, 'lowered' => $lowered];
    }

    /**
     * What to write for this goal / remembered goal / cap, or null when nothing.
     *
     * @return array{kind: 'limited'|'restored', to: int, before: int|null}|null
     */
    private function plan(int $goal, ?int $before, ?int $cap): ?array
    {
        if ($cap !== null) {
            return $goal > $cap
                ? ['kind' => DailyGoalChange::LIMITED, 'to' => $cap, 'before' => $before ?? $goal]
                : null;
        }

        if ($before === null) {
            return null;
        }

        return ['kind' => DailyGoalChange::RESTORED, 'to' => $this->restorable($before), 'before' => null];
    }

    /** The remembered goal when it is still offered, else the nearest lower option, else the smallest one. */
    private function restorable(int $before): int
    {
        $options = self::goalOptions();
        if (in_array($before, $options, true)) {
            return $before;
        }

        $lower = array_filter($options, static fn (int $o): bool => $o < $before);

        return $lower === [] ? ($options === [] ? $before : min($options)) : max($lower);
    }

    /** @return list<int> `learners.daily_goal_options` (10 / 20 / 40), ascending */
    public static function goalOptions(): array
    {
        $options = array_values(array_map('intval', (array) config('learners.daily_goal_options', [10, 20, 40])));
        sort($options);

        return $options;
    }
}
