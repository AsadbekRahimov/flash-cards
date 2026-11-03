<?php

declare(strict_types=1);

namespace App\Domain\Game\Services\Economy;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Engagement\Enums\ReferralStatus;
use App\Domain\Game\DTO\TicketGrant;
use App\Domain\Game\Enums\TicketSource;
use App\Domain\Game\Services\GameLimits;
use App\Domain\Learners\Support\LearnerRowLock;
use App\Models\AuditLog;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameTicketLedgerEntry;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The tickets of the Free runs (docs/18 §12.7.3): `game_profiles.tickets` plus the append-only `game_ticket_ledger` with a unique
 * `idempotency_key`. A ticket comes from the streak (+1 every 7 days), from an activated friend (+3 to the inviter), or from an
 * administrator; it goes only to a map run beyond the free run of the day. The balance is capped at `game.tickets.max`: what the cap
 * cuts off is not written (the event `game.ticket_capped` says so). Tickets do not expire and are kept through Pro.
 *
 * Every grant is one short transaction of its own, run AFTER the commit of its source (the streak, the referral, the audit entry):
 * `learners` (FOR NO KEY UPDATE, the lock of the Free limits - docs/18 §12.5.1 rule 18) -> `game_profiles` (made lazily) -> the check of
 * the key -> `game_ticket_ledger` INSERT -> UPDATE `game_profiles`. The spend runs inside the transaction of the start, under the same
 * lock, after the INSERT of the run (`game_runs` < `game_profiles`: the order of the finish). Because every change of `tickets` goes
 * through this class under that lock, `SUM(game_ticket_ledger.delta) = game_profiles.tickets` holds (the free run of the day is not
 * a ticket and is not in the ledger).
 *
 * A key written before answers `applied = false` with the balance after the first write: `referrals:recover`, a retried job and a
 * second tab are safe.
 */
final class TicketService
{
    public const AUDIT_ADJUSTED = 'game.tickets_adjusted';

    public function __construct(
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * The streak just reached `$streakCurrent` days on `$localDate` (`Y-m-d`): every 7th day gives `game.tickets.per_streak_week`
     * tickets, once per day (key `streak:{learner_id}:{local_date}`). Call after the commit of the streak (G2.10); everybody, Pro
     * included (the tickets wait for the end of the subscription). Nothing on the other days.
     */
    public function grantForStreak(Learner $learner, int $streakCurrent, string $localDate, ?CarbonImmutable $now = null): TicketGrant
    {
        $perWeek = max(0, (int) config('game.tickets.per_streak_week', 1));
        if ($streakCurrent <= 0 || $streakCurrent % 7 !== 0 || $perWeek === 0) {
            return new TicketGrant($this->balance((int) $learner->id), 0, 0, false);
        }

        return $this->grant($learner, $perWeek, TicketSource::Streak, "streak:{$learner->id}:{$localDate}", $now);
    }

    /**
     * A referral was rewarded (both sides, `ReferralService::reward`): the inviter gets `game.tickets.per_referral` tickets, once per
     * referral (key `referral:{id}`). A flagged referral, one that is not `rewarded`, a deleted or blocked inviter: nothing. The invited
     * learner gets no tickets (they have their days of Pro).
     */
    public function grantForReferral(Referral $referral, ?CarbonImmutable $now = null): ?TicketGrant
    {
        $perReferral = max(0, (int) config('game.tickets.per_referral', 3));
        if ($perReferral === 0 || $referral->flagged_at !== null || $referral->status() !== ReferralStatus::Rewarded) {
            return null;
        }

        /** @var Learner|null $inviter */
        $inviter = Learner::query()->find($referral->referrer_learner_id);
        if ($inviter === null || $inviter->blocked_at !== null) {
            return null;
        }

        return $this->grant($inviter, $perReferral, TicketSource::Referral, "referral:{$referral->id}", $now);
    }

    /**
     * An administrator changes the tickets by hand (docs/18 §12.7.3; the action of Filament is G2.11): the audit entry
     * `game.tickets_adjusted` first, in a transaction of its own, then the move under its key `admin:{audit_log_id}`. `$delta` > 0 is a
     * grant under the cap; `$delta` < 0 takes tickets, never below 0 - the ledger carries what was actually moved. The event
     * `game.tickets_adjusted {amount, audit_log_id}` names the user and the learner.
     */
    public function grantByAdmin(Learner $learner, int $delta, User $by, ?string $ip = null, ?string $reason = null, ?CarbonImmutable $now = null): TicketGrant
    {
        if ($delta === 0) {
            throw new LogicException('An adjustment of tickets takes a non-zero delta.');
        }

        $audit = DB::transaction(fn (): AuditLog => AuditLog::query()->create([
            'user_id' => $by->id,
            'action' => self::AUDIT_ADJUSTED,
            'entity_type' => Learner::class,
            'entity_id' => $learner->id,
            'ip' => $ip,
            'meta' => ['delta' => $delta, 'reason' => $reason],
        ]));

        $grant = $this->grant($learner, $delta, TicketSource::Admin, "admin:{$audit->id}", $now);
        if ($grant->applied) {
            $this->events->record(ProductEventName::GameTicketsAdjusted, ['amount' => $grant->delta, 'audit_log_id' => $audit->id], learner: $learner, user: $by);
        }

        return $grant;
    }

    /**
     * A ticket pays for the run just inserted (docs/18 §12.7.2): inside the transaction of the start, under the learner's lock that
     * `GameLimits::lockForRun()` took, after the INSERT of the run. One conditional UPDATE (`tickets > 0`: the claim read the ticket
     * under the same lock, so the row and the ticket are there - a miss is a bug of the caller) and the entry `spend:run:{run_id}`.
     */
    public function spendForRun(Learner $learner, GameRun $run, ?CarbonImmutable $now = null): TicketGrant
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A ticket is spent inside the transaction of the start, under the lock of the learner.');
        }
        $now ??= CarbonImmutable::now();

        $taken = GameProfile::query()
            ->where('learner_id', $learner->id)
            ->where('tickets', '>', 0)
            ->decrement('tickets', 1, ['updated_at' => $now]);
        if ($taken !== 1) {
            throw new LogicException("Learner {$learner->id} has no ticket to spend on run {$run->id}: the claim must have read one.");
        }

        GameTicketLedgerEntry::query()->create([
            'learner_id' => $learner->id,
            'delta' => -1,
            'source' => TicketSource::Spend,
            'idempotency_key' => "spend:run:{$run->id}",
            'run_id' => $run->id,
        ]);
        $balance = $this->balance((int) $learner->id);

        DB::afterCommit(fn () => $this->events->record(ProductEventName::GameTicketSpent, ['run_id' => $run->id], learner: $learner));

        return new TicketGrant($balance, -1, 0, true);
    }

    /** The balance without a lock (0 for a learner who has no profile yet). */
    public function balance(int $learnerId): int
    {
        return (int) GameProfile::query()->where('learner_id', $learnerId)->value('tickets');
    }

    /**
     * One move under the learner's lock, in a transaction of its own (a savepoint inside an outer one). `$delta` > 0 is cut to the
     * cap, `$delta` < 0 to the balance; what is left to move is written to the ledger and the profile. The events follow the commit.
     */
    private function grant(Learner $learner, int $delta, TicketSource $source, string $idempotencyKey, ?CarbonImmutable $now): TicketGrant
    {
        $now ??= CarbonImmutable::now();

        $grant = DB::transaction(function () use ($learner, $delta, $source, $idempotencyKey, $now): TicketGrant {
            LearnerRowLock::take((int) $learner->id);
            $profile = $this->lockedProfile((int) $learner->id, $now);

            $prior = GameTicketLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior !== null) {
                if ($prior->learner_id !== (int) $learner->id) {
                    throw new LogicException("The ticket key {$idempotencyKey} belongs to learner {$prior->learner_id}, not {$learner->id}.");
                }

                return new TicketGrant($profile->tickets, 0, 0, false);
            }

            $room = max(0, GameLimits::ticketsMax() - $profile->tickets);
            $moved = $delta > 0 ? min($delta, $room) : max($delta, -$profile->tickets);
            $lost = $delta > 0 ? $delta - $moved : 0;
            if ($moved === 0) {
                return new TicketGrant($profile->tickets, 0, $lost, false);
            }

            GameTicketLedgerEntry::query()->create([
                'learner_id' => $learner->id,
                'delta' => $moved,
                'source' => $source,
                'idempotency_key' => $idempotencyKey,
                'run_id' => null,
            ]);
            $balance = $profile->tickets + $moved;
            $profile->forceFill([
                'tickets' => $balance,
                'tickets_earned_total' => $profile->tickets_earned_total + max(0, $moved),
            ])->save();

            return new TicketGrant($balance, $moved, $lost, true);
        });

        if ($grant->applied) {
            DB::afterCommit(fn () => $this->events->record(ProductEventName::GameTicketGranted, ['source' => $source->value, 'delta' => $grant->delta, 'balance' => $grant->balance], learner: $learner));
        }
        if ($grant->lost > 0) {
            DB::afterCommit(fn () => $this->events->record(ProductEventName::GameTicketCapped, ['source' => $source->value, 'lost' => $grant->lost], learner: $learner));
        }

        return $grant;
    }

    /**
     * The learner's profile, made when it is missing - `insertOrIgnore` and the read again. Under the learner's lock no second writer
     * of this learner's profile exists (the finish touches it after `game_runs`, with one UPDATE that is fine with an existing row).
     */
    private function lockedProfile(int $learnerId, CarbonImmutable $now): GameProfile
    {
        $profile = GameProfile::query()->where('learner_id', $learnerId)->first();
        if ($profile !== null) {
            return $profile;
        }

        GameProfile::query()->insertOrIgnore([
            'learner_id' => $learnerId,
            'tickets' => 0,
            'tickets_earned_total' => 0,
            'stops_cleared' => 0,
            'cities_cleared' => 0,
            'last_run_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return GameProfile::query()->where('learner_id', $learnerId)->first()
            ?? throw new RuntimeException("The profile of learner {$learnerId} vanished after its insert.");
    }
}
