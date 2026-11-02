<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\DTO\DayBounds;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Practice\DTO\PracticeAccessState;
use App\Domain\Practice\DTO\PracticeLimitState;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Models\Battle;
use App\Models\Learner;
use App\Models\PracticeSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Free limits of the practice (docs/18 §3, G1; docs/16 §3.2). Per local day of the learner - the window of the quota of
 * new words (LearnerClock::limitWindowUtc: the current local day, carried over after a change of timezone so that no hopping
 * between zones shortens it) - a learner WITHOUT the entitlement feature `practice.unlimited` may:
 *
 *   - OPEN at most `practice.free_limits.sessions_per_day` solo sessions (`practice_sessions.started_at` in the window; a session
 *     that replaces the active one is a new one and counts). ONE retry of the mistakes per source is free - the earliest retry
 *     of a session that is not a retry itself and was opened in the window ({@see FREE_RETRY_SQL}); a second retry of the same
 *     source, a retry of a retry and a retry of an older session take a place like a session (the coordinator, 2026-11-02);
 *   - CALL at most `practice.free_limits.battles_per_day` battles (`battles.created_at` in the window, `challenger_id` = the
 *     learner: a rematch counts for the one who offers it; ACCEPTING is always free - a Pro friend brings Free friends in).
 *     A call that never started through no fault of the caller is given back ({@see UNSPENT_END_REASONS}): what counts is a
 *     call that waits (`invited`, `room`, `countdown`, `playing`) or was played (`finished`; `closed/both_absent`). An
 *     invitation that has run out but has not been closed yet is already not counted (the clock closes it on the way, Ж1).
 *
 * Pro - a subscription of their own or the sponsored Pro of a center - has the feature and no limit. `0` = only Pro creates;
 * an empty value = no limit of that kind for Free either (the block says `null` for it, as for Pro). `PRACTICE_PLAN=pro` is
 * older and stronger: Free never gets this far (403 `practice_pro_required`).
 *
 * Two readings, in the order of docs/16 §3.2 (access -> the switches -> the limit -> the rest):
 *   1. the courtesy check (`assertSessionAllowed` / `assertBattleAllowed`) runs before any work, WITHOUT a lock: a learner
 *      at the limit is refused before the material is read and the snapshot built;
 *   2. the claim (`claimSession` / `claimBattle`) runs INSIDE the transaction that creates the row, under `FOR NO KEY UPDATE`
 *      of the learner's row (the lock of CardPicker and of the quota of new words, docs/12 §5.3): two tabs racing for the last
 *      place are serialised there - the second counts after the first has committed and is refused. The learner's row is the
 *      FIRST lock of those transactions (`learners` -> `practice_sessions`; `learners` -> `battles` -> `battle_participants`),
 *      and nothing that holds a practice row ever waits for `learners` (docs/16 §9 rule 16): no cycle is possible. Only the
 *      learner who creates is locked, never the friend (rule 4 of docs/13 §3 kept). Pro takes no lock at all.
 *
 * A claim RETURNS its refusal instead of throwing it: the caller returns it out of the transaction and throws it after the
 * commit (the pattern of BattleService::transact), so that the event `practice.limit_reached` - registered here with
 * `DB::afterCommit`, like every event of the practice (docs/16 §3.4) - is recorded; a throw would roll the callback back.
 *
 * @phpstan-type LimitBlock array{limit: int, used: int, remaining: int, resets_at: string}
 * @phpstan-type LimitsBlock array{sessions: LimitBlock|null, battles: LimitBlock|null}
 */
final class PracticeLimits
{
    public const KIND_SESSIONS = 'sessions';

    public const KIND_BATTLES = 'battles';

    /** The entitlement feature that lifts every limit (`config/billing.php`, `plans.pro.features`). */
    public const FEATURE_UNLIMITED = 'practice.unlimited';

    /**
     * The ends of a battle that never started through no fault of the one who called - declined, run out, withdrawn, a room
     * that ran out, voided by `PRACTICE_ACCESS=off`: such a call is given back (the coordinator, 2026-11-02). Every other battle
     * of the day is a call: one that waits (no `end_reason` yet) or one that was played (`completed`, `surrender`, `absent`,
     * `both_absent`).
     *
     * @var list<string>
     */
    public const UNSPENT_END_REASONS = [
        BattleEndReason::Declined->value,
        BattleEndReason::InviteExpired->value,
        BattleEndReason::Canceled->value,
        BattleEndReason::RoomExpired->value,
        BattleEndReason::Voided->value,
    ];

    /**
     * The one FREE retry of a source (SQL over the counted row `practice_sessions`; two bindings: the start and the end of the
     * window): the row is a retry, its source is not a retry itself and was opened in the window, and the row is the earliest
     * retry of that source (the one the claim let through for free: {@see isFreeRetry()} checks "no retry yet" under the lock, so
     * the earliest is exactly that one). Every other session of the window counts.
     */
    private const FREE_RETRY_SQL = '(practice_sessions.retry_of_session_id is not null'
        .' and exists (select 1 from practice_sessions as src where src.id = practice_sessions.retry_of_session_id'
        .' and src.retry_of_session_id is null and src.started_at >= ? and src.started_at < ?)'
        .' and practice_sessions.id = (select min(earliest.id) from practice_sessions as earliest'
        .' where earliest.retry_of_session_id = practice_sessions.retry_of_session_id))';

    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly LearnerClock $clock,
        private readonly PracticeAccess $access,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The block `limits` of `/me.practice`, of the hub and of the 201 answers (docs/16 §3.3): `null` while the practice is not
     * open to the learner (`off`, not in the pilot, Free at `plan = pro`: nothing to count) or when no limit applies (Pro; both
     * limits empty); otherwise both kinds - a kind without a limit is `null` - counted in ONE statement. No lock.
     *
     * @return LimitsBlock|null
     */
    public function describe(Learner $learner, PracticeAccessState $state, ?Entitlement $entitlement = null, ?CarbonImmutable $now = null): ?array
    {
        if (! $state->isAvailable() || ! $this->anyLimitConfigured()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $entitlement ??= $this->entitlements->for($learner, $now);
        if ($this->isUnlimited($entitlement)) {
            return null;
        }

        $sessions = $this->limitOf(self::KIND_SESSIONS);
        $battles = $this->limitOf(self::KIND_BATTLES);
        $window = $this->clock->limitWindowUtc($learner, $now);
        $used = $this->usedOfBoth((int) $learner->id, $window, $now);

        return [
            self::KIND_SESSIONS => $sessions === null ? null : (new PracticeLimitState(self::KIND_SESSIONS, $sessions, $used[self::KIND_SESSIONS], $window->endUtc))->toArray(),
            self::KIND_BATTLES => $battles === null ? null : (new PracticeLimitState(self::KIND_BATTLES, $battles, $used[self::KIND_BATTLES], $window->endUtc))->toArray(),
        ];
    }

    /**
     * The same block for a learner alone (the 201 of an opening / an invitation): the entitlement from the cache, the state
     * from the flags.
     *
     * @return LimitsBlock|null
     */
    public function forLearner(Learner $learner, ?CarbonImmutable $now = null): ?array
    {
        // No limit configured at all: nothing to say, and the entitlement is not even looked up (a miss would be a query).
        if (! $this->anyLimitConfigured()) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $entitlement = $this->entitlements->for($learner, $now);

        return $this->describe($learner, $this->access->stateFor($learner, $entitlement), $entitlement, $now);
    }

    public function isUnlimited(Entitlement $entitlement): bool
    {
        return $entitlement->can(self::FEATURE_UNLIMITED);
    }

    private function anyLimitConfigured(): bool
    {
        return $this->limitOf(self::KIND_SESSIONS) !== null || $this->limitOf(self::KIND_BATTLES) !== null;
    }

    /**
     * The courtesy check before the work of an opening (no lock). `$retryOf` - the source of a retry of the mistakes: one retry per
     * source opened today is free, any other retry is a session.
     *
     * @throws PracticeException practice_limit_reached (403)
     */
    public function assertSessionAllowed(Learner $learner, CarbonImmutable $now, ?PracticeSession $retryOf = null): void
    {
        $refusal = $this->refusal(self::KIND_SESSIONS, $learner, $now, lock: false, retryOf: $retryOf);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * The courtesy check before the work of an invitation (no lock).
     *
     * @throws PracticeException practice_limit_reached (403)
     */
    public function assertBattleAllowed(Learner $learner, CarbonImmutable $now): void
    {
        $refusal = $this->refusal(self::KIND_BATTLES, $learner, $now, lock: false);
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /**
     * Takes the place of a session INSIDE the creating transaction: locks the learner's row (Free only), counts again. Returns
     * the refusal - the caller RETURNS it out of the transaction and throws it after the commit (see the class comment); `null`
     * = the place is the caller's, insert the row.
     */
    public function claimSession(Learner $learner, CarbonImmutable $now, ?PracticeSession $retryOf = null): ?PracticeException
    {
        return $this->refusal(self::KIND_SESSIONS, $learner, $now, lock: true, retryOf: $retryOf);
    }

    /** Takes the place of a battle call INSIDE the creating transaction; the same contract as {@see claimSession()}. */
    public function claimBattle(Learner $learner, CarbonImmutable $now): ?PracticeException
    {
        return $this->refusal(self::KIND_BATTLES, $learner, $now, lock: true);
    }

    private function refusal(string $kind, Learner $learner, CarbonImmutable $now, bool $lock, ?PracticeSession $retryOf = null): ?PracticeException
    {
        $limit = $this->limitOf($kind);
        if ($limit === null || $this->isUnlimited($this->entitlements->for($learner, $now))) {
            return null;
        }

        if ($lock) {
            $this->lockLearner((int) $learner->id);
        }
        $window = $this->clock->limitWindowUtc($learner, $now);
        if ($retryOf !== null && $this->isFreeRetry($retryOf, $window)) {
            return null;
        }
        $used = $this->usedOf($kind, (int) $learner->id, $window, $now);
        if ($used < $limit) {
            return null;
        }

        DB::afterCommit(fn () => $this->events->record(ProductEventName::PracticeLimitReached, ['kind' => $kind, 'limit' => $limit], learner: $learner));

        return PracticeException::limitReached(new PracticeLimitState($kind, $limit, $used, $window->endUtc));
    }

    /** The limit of a kind from the config; `null` = no limit (an empty value), never negative. */
    private function limitOf(string $kind): ?int
    {
        $value = config('practice.free_limits.'.($kind === self::KIND_SESSIONS ? 'sessions_per_day' : 'battles_per_day'));
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * `SELECT ... FOR NO KEY UPDATE` of the learner's row on PostgreSQL (the lock of CardPicker: it serialises the learner's own
     * transactions without blocking the rows that merely reference the learner), `FOR UPDATE` elsewhere (SQLite ignores it: one
     * connection, the whole database is the lock).
     */
    private function lockLearner(int $learnerId): void
    {
        $lock = Learner::query()->whereKey($learnerId);
        (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->value('id');
    }

    /**
     * ONE retry per source is free: the source is not a retry itself, was opened in the window, and has no retry yet. The claim
     * asks under the lock of the learner's row, so two tabs retrying the same source at once get one free place, not two.
     */
    private function isFreeRetry(PracticeSession $source, DayBounds $window): bool
    {
        $openedAt = CarbonImmutable::make($source->started_at);

        return $source->retry_of_session_id === null
            && $openedAt !== null
            && $window->contains($openedAt)
            && ! PracticeSession::query()->where('retry_of_session_id', $source->id)->exists();
    }

    private function usedOf(string $kind, int $learnerId, DayBounds $window, CarbonImmutable $now): int
    {
        if ($kind === self::KIND_SESSIONS) {
            return PracticeSession::query()
                ->where('learner_id', $learnerId)
                ->where('started_at', '>=', $window->startUtc)
                ->where('started_at', '<', $window->endUtc)
                ->whereRaw('not '.self::FREE_RETRY_SQL, [self::sql($window->startUtc), self::sql($window->endUtc)])
                ->count();
        }

        return Battle::query()
            ->where('challenger_id', $learnerId)
            ->where('created_at', '>=', $window->startUtc)
            ->where('created_at', '<', $window->endUtc)
            ->where(function (Builder $spent): void {
                $spent->whereNull('end_reason')->orWhereNotIn('end_reason', self::UNSPENT_END_REASONS);
            })
            ->whereNot(function (Builder $runOut) use ($now): void {
                $runOut->where('status', BattleStatus::Invited->value)->where('invite_expires_at', '<=', $now->utc());
            })
            ->count();
    }

    /** A UTC instant as the database stores it. */
    private static function sql(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Both counts in one statement (the hub and `/me` pay one query, not two). The same predicates as {@see usedOf()}; the
     * indexes `practice_sessions (learner_id, status, started_at)` and `battles (challenger_id, status)` bound the scan to the
     * learner's own rows.
     *
     * @return array{sessions: int, battles: int}
     */
    private function usedOfBoth(int $learnerId, DayBounds $window, CarbonImmutable $now): array
    {
        $start = self::sql($window->startUtc);
        $end = self::sql($window->endUtc);
        $unspent = implode(', ', array_fill(0, count(self::UNSPENT_END_REASONS), '?'));

        $row = DB::selectOne(
            'select'
            .' (select count(*) from practice_sessions where learner_id = ? and started_at >= ? and started_at < ? and not '.self::FREE_RETRY_SQL.') as sessions,'
            .' (select count(*) from battles where challenger_id = ? and created_at >= ? and created_at < ?'
            ." and (end_reason is null or end_reason not in ({$unspent})) and not (status = ? and invite_expires_at <= ?)) as battles",
            [
                $learnerId, $start, $end, $start, $end,
                $learnerId, $start, $end, ...self::UNSPENT_END_REASONS, BattleStatus::Invited->value, self::sql($now),
            ],
        );

        return [
            self::KIND_SESSIONS => (int) ($row->sessions ?? 0),
            self::KIND_BATTLES => (int) ($row->battles ?? 0),
        ];
    }
}
