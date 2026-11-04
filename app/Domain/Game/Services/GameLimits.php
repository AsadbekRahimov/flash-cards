<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\DTO\GameLimitState;
use App\Domain\Game\DTO\PendingRunClaim;
use App\Domain\Game\DTO\RunClaim;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Support\GameWeek;
use App\Domain\Learners\DTO\DayBounds;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Support\LearnerRowLock;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Practice\Support\IsoTime;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The Free limits of the game "Caravan" (docs/18 §3, §12.7.2; the model is PracticeLimits). A learner WITHOUT the feature of the plan:
 *
 *   - RUNS: at most `game.free_limits.runs_per_day` map runs (`stop` + `boss`) per local day (the window of LearnerClock::limitWindowUtc,
 *     the one of the practice and of the quota of new words); beyond it a run costs a ticket (`game_profiles.tickets`), and without
 *     a ticket it is refused - 403 `game_limit_reached`. A run that was abandoned or ran out WITHOUT a single answer gives its place
 *     back; an active run holds one. The feature `game.unlimited_runs` lifts it (no lock, no ticket);
 *   - BOSSES: only the bosses of the first `boss_stages` cities (by `stages.number`) - 403 `game_pro_required {kind: boss, stage_number}`;
 *     the feature `game.all_bosses` lifts it. The fight itself costs a run of the day or a ticket, like a stop;
 *   - BOOSTERS: at most `boosters_per_week` uses per local week (Monday-based, GameWeek); Pro with `game.boosters_plus` has
 *     `game.pro_limits.boosters_per_week` instead - 409 `booster_limit_reached {limit, used, resets_at}`.
 *
 * A limit is an integer from the config: `0` = only Pro, an empty value = no limit for Free either. The daily caravan and the boss of a
 * class have no limit here (one per day / per caravan is a fact of the database).
 *
 * The RUNS limit is read twice, in the order of docs/16 §3.2: the courtesy check (`assertRunAllowed`) before the material is read -
 * no lock, a learner at the limit without a ticket is refused before the snapshot is built; and the claim INSIDE the transaction of
 * the start, in two phases: `lockForRun()` - the learner's row FOR NO KEY UPDATE, the FIRST statement of the transaction (docs/18
 * §12.5.1 rule 18: the lock of CardPicker, PracticeLimits and the grants of tickets; nothing holding a row of the game ever waits
 * for it, so no cycle); then the caller handles the active run (the same stop in a second tab is returned as it is; one closed
 * without an answer gives its place back); then `claimRun()` counts under the lock and decides what pays. Tickets change only under
 * that lock (TicketService), so "there is a ticket" read here is final. A refusal is RETURNED, not thrown: the caller returns it
 * out of the transaction and throws it after the commit, so that the event `game.limit_reached` registered here with
 * `DB::afterCommit` is recorded (the pattern of PracticeLimits).
 *
 * The BOOSTERS limit is decided under the lock of the run (the runs of a learner are serialised by `active_learner_id`): one method,
 * `assertBoosterAllowed`, serves the courtesy and the claim alike (G2.5b).
 *
 * @phpstan-import-type LimitsBlock from GameLimitState
 */
final class GameLimits
{
    public const KIND_RUNS = GameException::KIND_RUNS;

    public const KIND_BOSS = GameException::KIND_BOSS;

    public const KIND_BOOSTERS = GameException::KIND_BOOSTERS;

    /** The features of the plan (`config/billing.php`, `plans.pro.features`) that lift the limits. */
    public const FEATURE_UNLIMITED_RUNS = 'game.unlimited_runs';

    public const FEATURE_ALL_BOSSES = 'game.all_bosses';

    public const FEATURE_BOOSTERS_PLUS = 'game.boosters_plus';

    /** The kinds of runs the daily limit counts: the map runs. */
    private const COUNTED_KINDS = [RunKind::Stop, RunKind::Boss];

    /** The ends of a run that give the place back when the run had no answer (docs/18 §12.7.2). */
    private const UNSPENT_END_REASONS = [RunFinishReason::Abandoned, RunFinishReason::Expired];

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerClock $clock,
        private readonly ProductEventRecorder $events,
    ) {}

    // ------------------------------------------------------------------ the block of /me

    /**
     * The limits and the balances of the learner in ONE statement (the block `limits` of `/me.game` and the balances next to it,
     * docs/04 A.2). No lock. The caller has checked that the game is open to the learner.
     */
    public function state(Learner $learner, ?Entitlement $entitlement = null, ?CarbonImmutable $now = null): GameLimitState
    {
        $now ??= CarbonImmutable::now();
        $entitlement ??= $this->entitlements->for($learner, $now);
        $window = $this->clock->limitWindowUtc($learner, $now);
        $today = $this->clock->today($learner, $now);
        $weekStart = GameWeek::start($today);

        $row = DB::selectOne(
            'select'
            .' (select count(*) from game_runs where learner_id = ? and kind in (?, ?) and started_at >= ? and started_at < ?'
            .' and (finish_reason is null or finish_reason not in (?, ?) or answered > 0)) as runs_used,'
            .' (select count(*) from game_booster_uses where learner_id = ? and week_start = ?) as boosters_used,'
            .' (select tickets from game_profiles where learner_id = ?) as tickets,'
            .' (select balance from game_wallets where learner_id = ?) as coins',
            [
                $learner->id, ...self::countedKindValues(), self::sql($window->startUtc), self::sql($window->endUtc), ...self::unspentReasonValues(),
                $learner->id, StoredDate::of($weekStart->toDateString()),
                $learner->id,
                $learner->id,
            ],
        );

        return new GameLimitState(
            runsLimit: $this->runsLimit($entitlement),
            runsUsed: (int) ($row->runs_used ?? 0),
            runsResetAt: $window->endUtc,
            tickets: (int) ($row->tickets ?? 0),
            ticketsMax: self::ticketsMax(),
            boostersLimit: $this->boostersLimit($entitlement),
            boostersUsed: (int) ($row->boosters_used ?? 0),
            boostersResetAt: GameWeek::end($today)->utc(),
            bossStages: $this->bossStages($entitlement),
            coins: (int) ($row->coins ?? 0),
        );
    }

    /**
     * The block `limits` for a learner alone (the map, the answer of a start): `null` while the game is not open to them.
     *
     * @return LimitsBlock|null
     */
    public function describe(Learner $learner, bool $available, ?Entitlement $entitlement = null, ?CarbonImmutable $now = null): ?array
    {
        return $available ? $this->state($learner, $entitlement, $now)->toArray() : null;
    }

    // ------------------------------------------------------------------ runs

    /**
     * The courtesy check of a map run before the material is read (no lock): the runs of the day are used up and there is no ticket.
     * `daily` and `class_boss` pass.
     *
     * @throws GameException game_limit_reached (403)
     */
    public function assertRunAllowed(Learner $learner, RunKind $kind, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $limit = $this->limitFor($learner, $kind, $now);
        if ($limit === null) {
            return;
        }

        $refusal = $this->runRefusal($learner, $limit, $this->clock->limitWindowUtc($learner, $now));
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * Phase 1 of the claim, the FIRST statement of the transaction of a start: the learner's row is locked for a Free learner with a
     * limit on a map run; `null` when nothing applies (Pro with `game.unlimited_runs`, an empty limit, `daily` / `class_boss`) - and
     * then no lock is taken at all.
     */
    public function lockForRun(Learner $learner, RunKind $kind, CarbonImmutable $now): ?PendingRunClaim
    {
        $limit = $this->limitFor($learner, $kind, $now);
        if ($limit === null) {
            return null;
        }

        LearnerRowLock::take((int) $learner->id);

        return new PendingRunClaim($learner, $limit, $now);
    }

    /**
     * Phase 2, under the lock, after the active run has been dealt with: counts the runs of the day and the tickets, and says what pays
     * - the free run of the day, a ticket (the caller spends it with TicketService::spendForRun after the INSERT of the run, in this
     * very transaction), or the refusal (returned; the caller throws it after the commit).
     */
    public function claimRun(PendingRunClaim $pending): RunClaim|GameException
    {
        $window = $this->clock->limitWindowUtc($pending->learner, $pending->now);
        [$used, $tickets] = $this->usedAndTickets((int) $pending->learner->id, $window);

        if ($used < $pending->limit) {
            return RunClaim::dailySlot();
        }
        if ($tickets > 0) {
            return RunClaim::ticket();
        }

        return $this->refuseRuns($pending->learner, $pending->limit, $used, $window);
    }

    // ------------------------------------------------------------------ bosses (G2.4)

    /**
     * The boss of the city `$stageNumber` is open to the learner: Free fights the bosses of the first `boss_stages` cities only. Before
     * the material is read; the fight itself is claimed like a stop (`lockForRun` / `claimRun` with `RunKind::Boss`).
     *
     * @throws GameException game_pro_required {kind: boss, stage_number} (403)
     */
    public function assertBossAllowed(Learner $learner, int $stageNumber, ?CarbonImmutable $now = null): void
    {
        $limit = $this->bossStages($this->entitlements->for($learner, $now ?? CarbonImmutable::now()));
        if ($limit === null || $stageNumber <= $limit) {
            return;
        }

        $this->recordLimitReached($learner, self::KIND_BOSS, $limit);

        throw GameException::proRequired(self::KIND_BOSS, $stageNumber);
    }

    // ------------------------------------------------------------------ boosters (G2.5b)

    /**
     * The learner may use one more booster this week. Call under the lock of the run (the runs of a learner are serialised, so the count
     * is final there); the courtesy reading is the same call without the lock.
     *
     * @throws GameException booster_limit_reached {limit, used, resets_at} (409)
     */
    public function assertBoosterAllowed(Learner $learner, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $limit = $this->boostersLimit($this->entitlements->for($learner, $now));
        if ($limit === null) {
            return;
        }

        $today = $this->clock->today($learner, $now);
        $used = DB::table('game_booster_uses')
            ->where('learner_id', $learner->id)
            ->where('week_start', StoredDate::of(GameWeek::start($today)->toDateString()))
            ->count();
        if ($used < $limit) {
            return;
        }

        $this->recordLimitReached($learner, self::KIND_BOOSTERS, $limit);

        throw GameException::boosterLimitReached($limit, $used, IsoTime::format(GameWeek::end($today)->utc()));
    }

    // ------------------------------------------------------------------ the limits of the plan

    /** The daily limit of map runs for the learner's plan; `null` = none applies (Pro, or an empty config value). */
    public function runsLimit(Entitlement $entitlement): ?int
    {
        return $entitlement->can(self::FEATURE_UNLIMITED_RUNS) ? null : self::configured('game.free_limits.runs_per_day');
    }

    /** How many first cities have their boss open to the learner's plan; `null` = all of them. */
    public function bossStages(Entitlement $entitlement): ?int
    {
        return $entitlement->can(self::FEATURE_ALL_BOSSES) ? null : self::configured('game.free_limits.boss_stages');
    }

    /** The weekly limit of boosters for the learner's plan; `null` = none. */
    public function boostersLimit(Entitlement $entitlement): ?int
    {
        return self::configured($entitlement->can(self::FEATURE_BOOSTERS_PLUS) ? 'game.pro_limits.boosters_per_week' : 'game.free_limits.boosters_per_week');
    }

    /** The cap of the balance of tickets (`game.tickets.max`), never negative. */
    public static function ticketsMax(): int
    {
        return max(0, (int) config('game.tickets.max', 10));
    }

    // ------------------------------------------------------------------ internals

    /** The limit of the day that applies to this run of this learner, `null` when none does. */
    private function limitFor(Learner $learner, RunKind $kind, CarbonImmutable $now): ?int
    {
        if (! in_array($kind, self::COUNTED_KINDS, true)) {
            return null;
        }
        if (self::configured('game.free_limits.runs_per_day') === null) {
            return null; // nothing to lift: the entitlement is not even looked up
        }

        return $this->runsLimit($this->entitlements->for($learner, $now));
    }

    private function runRefusal(Learner $learner, int $limit, DayBounds $window): ?GameException
    {
        [$used, $tickets] = $this->usedAndTickets((int) $learner->id, $window);
        if ($used < $limit || $tickets > 0) {
            return null;
        }

        return $this->refuseRuns($learner, $limit, $used, $window);
    }

    private function refuseRuns(Learner $learner, int $limit, int $used, DayBounds $window): GameException
    {
        $this->recordLimitReached($learner, self::KIND_RUNS, $limit);

        return GameException::limitReached([
            'kind' => self::KIND_RUNS,
            'limit' => $limit,
            'used' => $used,
            'tickets' => 0,
            'resets_at' => IsoTime::format($window->endUtc),
        ]);
    }

    /**
     * The map runs of the window that hold a place, and the tickets, in one statement. The index `game_runs (learner_id, status,
     * started_at)` bounds the scan to the learner's own rows.
     *
     * @return array{0: int, 1: int}
     */
    private function usedAndTickets(int $learnerId, DayBounds $window): array
    {
        $row = DB::selectOne(
            'select'
            .' (select count(*) from game_runs where learner_id = ? and kind in (?, ?) and started_at >= ? and started_at < ?'
            .' and (finish_reason is null or finish_reason not in (?, ?) or answered > 0)) as runs_used,'
            .' (select tickets from game_profiles where learner_id = ?) as tickets',
            [$learnerId, ...self::countedKindValues(), self::sql($window->startUtc), self::sql($window->endUtc), ...self::unspentReasonValues(), $learnerId],
        );

        return [(int) ($row->runs_used ?? 0), (int) ($row->tickets ?? 0)];
    }

    /** After the commit (at once outside a transaction), like every event of the limits. */
    private function recordLimitReached(Learner $learner, string $kind, int $limit): void
    {
        DB::afterCommit(fn () => $this->events->record(ProductEventName::GameLimitReached, ['kind' => $kind, 'limit' => $limit], learner: $learner));
    }

    /** A limit of the config; `null` = no limit (an empty value), never negative. */
    private static function configured(string $key): ?int
    {
        $value = config($key);
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return max(0, (int) $value);
    }

    /** @return list<string> */
    private static function countedKindValues(): array
    {
        return array_map(static fn (RunKind $kind): string => $kind->value, self::COUNTED_KINDS);
    }

    /** @return list<string> */
    private static function unspentReasonValues(): array
    {
        return array_map(static fn (RunFinishReason $reason): string => $reason->value, self::UNSPENT_END_REASONS);
    }

    /** A UTC instant as the database stores it. */
    private static function sql(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
