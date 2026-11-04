<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Runs;

use App\Domain\Engagement\Services\AchievementService;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Game\DTO\RunFinishOutcome;
use App\Domain\Game\DTO\RunState;
use App\Domain\Game\Enums\LedgerKind;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\RunStatus;
use App\Domain\Game\Services\Class\ClassCaravanService;
use App\Domain\Game\Services\Economy\WalletService;
use App\Domain\Game\Support\RunScoring;
use App\Domain\Learners\Enums\StreakHeldBy;
use App\Domain\Learners\Services\StreakService;
use App\Domain\Learners\Support\StoredDate;
use App\Jobs\SendGameCardJob;
use App\Models\GameCityProgress;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameStopProgress;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * The transaction of a finish (docs/18 §12.5.3): a run played to its end (`completed` / `failed`) gets its stars, its coins, moves
 * the map and marks the day of the learner. Runs INSIDE the transaction of the answer, under the `FOR UPDATE` of the run - the
 * answer's own changes to the run arrive unsaved and leave in the one UPDATE of the run that ends this method.
 *
 * Locks, in order (docs/18 §12.5.1, rules 19-20): `game_runs` (held by the caller) -> `game_stop_progress` / `game_city_progress`
 * (the learner's own row, `insertOrIgnore` + FOR UPDATE: the reward depends on the stars / the wins so far) -> `game_wallets`
 * (WalletService) -> `game_coin_ledger` -> `game_profiles` (one conditional UPDATE) -> `learner_daily_stats` (the row of
 * `game_runs.local_date`, `insertOrIgnore` + FOR UPDATE - the transaction of a grade ends on the same row and never waits for
 * a row of the game) -> `class_caravans` (FOR UPDATE, the one row of the caravan the run joined at its start) -> `class_caravan_members`
 * (the learner's own row), last (G2.9a, rule 21). Nothing of the learning is touched.
 *
 * Idempotent by construction: a run is finished once (the caller checks `status = active` under the lock), the reward is one entry
 * `run:{id}:reward` (unique), the day is held once (`game_held_at` set only while NULL).
 *
 * After the commit (step 7, docs/18 §12.6): the day held by this run goes to the streak (`StreakService::apply(…, Game)` - unless the
 * goal had held it already), a run that is the first activity of the day seats the learner in the league (`LeagueService::place`,
 * idempotent), and the achievements are looked at when the day was held (the streak projection is committed by then). Each hook on its
 * own, reported and never a failed finish - like the hooks of DailyStatsService after a grade. The first win over the boss of a city
 * adds the event `game.boss_defeated`, the achievement `game_first_boss` and the card DM (G2.4).
 */
final class RunFinisher
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly RunEventRecorder $events,
        private readonly StreakService $streaks,
        private readonly LeagueService $leagues,
        private readonly AchievementService $achievements,
        private readonly ClassCaravanService $caravans,
    ) {}

    /**
     * @param  GameRun  $run  the locked, still active run, with the attributes of the last answer filled but not saved
     */
    public function finish(GameRun $run, RunFinishReason $reason, Learner $learner, CarbonImmutable $now): RunFinishOutcome
    {
        if (! $reason->pays()) {
            throw new LogicException("A run closed {$reason->value} is not finished here: nothing is paid for it.");
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A finish must run inside the transaction of the answer, under the lock of the run.');
        }
        if ($run->status !== RunStatus::Active) {
            throw new LogicException("The run {$run->id} is finished already.");
        }

        $state = RunState::fromArray($run->state);
        $isBoss = $run->kind === RunKind::Boss || $run->kind === RunKind::ClassBoss;
        $bossWon = $isBoss && RunScoring::bossWon($run->correct, $run->total, (float) config('game.boss.required_accuracy', 0.8));
        $stars = $isBoss ? RunScoring::bossStars($bossWon) : RunScoring::stars($state->flaskLosses(), $reason === RunFinishReason::Failed);

        // 3a. The row of the map, locked first: the reward (step 2) is decided from what it says.
        $stop = null;
        $city = null;
        $firstClear = false;
        $bossFirst = false;
        $kind = LedgerKind::RunReward;
        $amount = 0;
        switch ($run->kind) {
            case RunKind::Stop:
                $stop = $this->lockedStop($run, $now);
                $firstClear = $stop->first_cleared_at === null && $stars >= 1;
                $amount = $this->stopReward($reason, $stars, $stop);
                break;
            case RunKind::Boss:
                $city = $this->lockedCity($run, $now);
                $bossFirst = $bossWon && $city->boss_defeated_at === null;
                $kind = LedgerKind::BossReward;
                $amount = $bossWon ? $this->economy($bossFirst ? 'boss_first' : 'boss_repeat') : 0;
                break;
            case RunKind::Daily:
                $kind = LedgerKind::DailyReward;
                $amount = $reason === RunFinishReason::Completed ? $this->economy('daily') + $this->economy('daily_per_star') * $stars : 0;
                break;
            case RunKind::ClassBoss:
                $kind = LedgerKind::ClassReward;
                $amount = $bossWon ? $this->economy('class_boss') : 0;
                break;
        }

        // 2. The coins: one entry per run, nothing for a reward of zero.
        $entry = $this->wallet->credit($learner->id, $amount, $kind, "run:{$run->id}:reward", 'game_run', $run->id, ['stars' => $stars, 'run_kind' => $run->kind->value]);

        // 3b. The map.
        if ($stop !== null) {
            $stop->forceFill([
                'stars' => max($stop->stars, $stars),
                'best_score' => max($stop->best_score, $run->score),
                'runs_count' => $stop->runs_count + 1,
                'first_cleared_at' => $firstClear ? $now : $stop->first_cleared_at,
                'last_run_at' => $now,
            ])->save();
        }
        if ($city !== null) {
            $accuracy = RunScoring::bossAccuracy($run->correct, $run->total);
            $city->forceFill([
                'boss_attempts' => $city->boss_attempts + 1,
                'boss_wins' => $city->boss_wins + ($bossWon ? 1 : 0),
                'boss_best_accuracy' => max($city->boss_best_accuracy, $accuracy),
                'boss_defeated_at' => $bossFirst ? $now : $city->boss_defeated_at,
            ])->save();
        }

        // 4. The profile: the counters move by one exactly when this run set the first mark (equivalent to a recount, cheaper).
        $this->touchProfile($learner->id, $firstClear ? 1 : 0, $bossFirst ? 1 : 0, $now);

        // 5. The day of the start of the run (`local_date`), last: the league points under the cap, the day held by the game.
        $day = $this->lockedDay($learner->id, $run->local_date->toDateString(), $now);
        $earned = $run->correct * $this->league('correct') + ($run->typos + $run->wrong + $run->hints) * $this->league('answer');
        $cap = max(0, (int) config('game.league.daily_cap', 40));
        $leaguePoints = max(0, min($cap, $day->game_points + $earned) - $day->game_points);
        $heldDay = $day->game_held_at === null;
        $goalMetBefore = $day->goal_met_at !== null;
        $firstRunOfDay = $day->game_runs_count === 0;
        $reviewsCount = $day->reviews_count;
        $day->forceFill([
            'game_runs_count' => $day->game_runs_count + 1,
            'game_points' => $day->game_points + $leaguePoints,
            'game_held_at' => $heldDay ? $now : $day->game_held_at,
        ])->save();

        // 6. The class caravan (G2.9a, docs/18 §12.5.3 step 6), last of the locks: the caravan's row FOR UPDATE (read again as active and
        //    inside its window), the member's row, the totals - nothing when the run is in no caravan or the caravan is over.
        $this->caravans->contribute($run, $stars, $bossWon, $now);

        // 1. The run: the one UPDATE, with the attributes of the answer that arrived unsaved. The first win over the boss of the city is
        // kept in the state (a finished run's `summary.boss.first` is read from it; the map row only says WHEN the city fell).
        if ($bossFirst) {
            $run->forceFill(['state' => [...$run->state, 'boss_first' => true]]);
        }
        $run->forceFill([
            'status' => RunStatus::Finished,
            'finish_reason' => $reason,
            'finished_at' => $now,
            'stars' => $stars,
            'active_learner_id' => null,
            'coins_awarded' => $amount,
            'league_points' => $leaguePoints,
        ])->save();

        $outcome = new RunFinishOutcome(
            stars: $stars,
            coins: $amount,
            coinsKind: $kind,
            balance: $entry->balance,
            leaguePoints: $leaguePoints,
            heldDay: $heldDay,
            goalMetBefore: $goalMetBefore,
            firstRunOfDay: $firstRunOfDay,
            reviewsCount: $reviewsCount,
            firstClear: $firstClear,
            bossWon: $bossWon,
            bossFirst: $bossFirst,
        );

        // 7. After the commit, each on its own (a failed hook is reported, never a failed finish).
        $localDate = $run->local_date->toDateString();
        $isBossFight = $run->kind === RunKind::Boss;
        DB::afterCommit(function () use ($run, $learner, $entry, $amount, $kind, $outcome, $localDate, $now, $isBossFight): void {
            $this->hook(fn () => $this->events->finished($run, $learner));
            if ($entry->applied) {
                $this->hook(fn () => $this->events->coinsEarned($run, $learner, $amount, $kind, $entry->balance));
            }
            // The streak: the day of the START of the run, held by the game - unless the goal held it first (its own hook counted it).
            // A goal met at the same instant meets this on the optimistic UPDATE of `learners`: one of the two counts, +1 once.
            if ($outcome->heldDay && ! $outcome->goalMetBefore) {
                $this->hook(fn () => $this->streaks->apply($learner, $localDate, $now, StreakHeldBy::Game));
            }
            // The league: a run as the first activity of the day seats the learner in this week's group (a no-op once placed;
            // leagues off, opt-out and a block are place()'s own checks).
            if ($outcome->firstRunOfDay && $outcome->reviewsCount === 0) {
                $this->hook(fn () => $this->leagues->place($learner, $now));
            }
            // The counting achievements (streak) - after the streak hook: evaluate() reads `streak_best` fresh from the database.
            if ($outcome->heldDay) {
                $this->hook(fn () => $this->achievements->evaluate($learner, $now));
            }
            // The boss of a city beaten (docs/18 §12.3.4): the event on every win; the first win of the city also unlocks
            // `game_first_boss` (once per learner) and sends the card "I got to {city}" (once per learner and city).
            if ($isBossFight && $outcome->bossWon) {
                $this->hook(fn () => $this->events->bossDefeated($run, $learner, $outcome->bossFirst));
            }
            if ($isBossFight && $outcome->bossFirst) {
                $this->hook(fn () => $this->achievements->onGameBossDefeated($learner, (int) $run->stage_id, $now));
                $this->hook(fn () => SendGameCardJob::dispatch($learner->id, (int) $run->stage_id));
            }
        });

        return $outcome;
    }

    /**
     * The coins of a stop (docs/18 §12.5.3 step 2): the first clearing pays `stop_first` plus `per_new_star` for every star above the
     * best so far; any later run with new stars pays `per_new_star` for each; a completion without new stars pays `stop_repeat`; a
     * failed run pays `stop_repeat` only on a stop cleared before, else nothing.
     */
    private function stopReward(RunFinishReason $reason, int $stars, GameStopProgress $stop): int
    {
        $newStars = max(0, $stars - $stop->stars);
        if ($reason === RunFinishReason::Completed) {
            if ($newStars > 0) {
                return ($stop->first_cleared_at === null ? $this->economy('stop_first') : 0) + $this->economy('per_new_star') * $newStars;
            }

            return $this->economy('stop_repeat');
        }

        return $stop->first_cleared_at !== null ? $this->economy('stop_repeat') : 0;
    }

    private function lockedStop(GameRun $run, CarbonImmutable $now): GameStopProgress
    {
        $lessonId = $run->lesson_id ?? throw new LogicException("The run {$run->id} of a stop has no lesson.");
        $stageId = $run->stage_id ?? throw new LogicException("The run {$run->id} of a stop has no stage.");

        $row = GameStopProgress::query()->where('learner_id', $run->learner_id)->where('lesson_id', $lessonId)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }

        GameStopProgress::query()->insertOrIgnore([
            'learner_id' => $run->learner_id,
            'lesson_id' => $lessonId,
            'course_id' => $run->course_id,
            'stage_id' => $stageId,
            'stars' => 0,
            'best_score' => 0,
            'runs_count' => 0,
            'first_cleared_at' => null,
            'last_run_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return GameStopProgress::query()->where('learner_id', $run->learner_id)->where('lesson_id', $lessonId)->lockForUpdate()->first()
            ?? throw new RuntimeException("The progress of learner {$run->learner_id} on lesson {$lessonId} vanished after its insert.");
    }

    private function lockedCity(GameRun $run, CarbonImmutable $now): GameCityProgress
    {
        $stageId = $run->stage_id ?? throw new LogicException("The run {$run->id} of a boss has no stage.");

        $row = GameCityProgress::query()->where('learner_id', $run->learner_id)->where('stage_id', $stageId)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }

        GameCityProgress::query()->insertOrIgnore([
            'learner_id' => $run->learner_id,
            'stage_id' => $stageId,
            'course_id' => $run->course_id,
            'boss_attempts' => 0,
            'boss_defeated_at' => null,
            'boss_best_accuracy' => 0,
            'boss_wins' => 0,
            'shared_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return GameCityProgress::query()->where('learner_id', $run->learner_id)->where('stage_id', $stageId)->lockForUpdate()->first()
            ?? throw new RuntimeException("The progress of learner {$run->learner_id} in stage {$stageId} vanished after its insert.");
    }

    /** One UPDATE of the learner's profile; the row is made with the values when it is missing (`insertOrIgnore`, then the UPDATE again). */
    private function touchProfile(int $learnerId, int $stopsCleared, int $citiesCleared, CarbonImmutable $now): void
    {
        $columns = array_filter(['stops_cleared' => $stopsCleared, 'cities_cleared' => $citiesCleared]);
        $touch = static fn (): int => $columns === []
            ? GameProfile::query()->where('learner_id', $learnerId)->update(['last_run_at' => $now])
            : GameProfile::query()->where('learner_id', $learnerId)->incrementEach($columns, ['last_run_at' => $now]);

        if ($touch() > 0) {
            return;
        }

        $inserted = GameProfile::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'tickets' => 0,
            'tickets_earned_total' => 0,
            'stops_cleared' => $stopsCleared,
            'cities_cleared' => $citiesCleared,
            'last_run_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            $touch(); // somebody made the row between the UPDATE and the INSERT: it takes the counters after all
        }
    }

    /** The row of the learner's day, locked, made first when missing (the way DailyStatsService::lockedRow does it). */
    private function lockedDay(int $learnerId, string $localDate, CarbonImmutable $now): LearnerDailyStats
    {
        $stored = StoredDate::of($localDate);

        $row = LearnerDailyStats::query()->where('learner_id', $learnerId)->where('date', $stored)->lockForUpdate()->first();
        if ($row !== null) {
            return $row;
        }

        LearnerDailyStats::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'date' => $stored,
            'reviews_count' => 0,
            'new_words_count' => 0,
            'correct_count' => 0,
            'time_spent_ms' => 0,
            'goal_met_at' => null,
            'game_runs_count' => 0,
            'game_points' => 0,
            'game_held_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return LearnerDailyStats::query()->where('learner_id', $learnerId)->where('date', $stored)->lockForUpdate()->first()
            ?? throw new RuntimeException("The day {$localDate} of learner {$learnerId} vanished after its insert.");
    }

    private function economy(string $key): int
    {
        return max(0, (int) config("game.economy.{$key}", 0));
    }

    private function league(string $key): int
    {
        return max(0, (int) config("game.league.{$key}", 0));
    }

    private function hook(callable $hook): void
    {
        try {
            $hook();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
