<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Practice\DTO\BattleTransitionReport;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\Verdict;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The clocks of a battle (docs/16 §8.3, §8.4): the ONE place where a match moves by time and by presence, and the one place
 * that writes a terminal status. Every method that writes is called under `FOR UPDATE` of the `battles` row with the
 * participants loaded fresh (BattleService, BattleReader when a transition is due, BattleJanitor); {@see isDue()} and the
 * presence helpers read only.
 *
 * `advance()` is idempotent: it looks at the row and `$now`, writes what is due (several steps at once when a client was
 * away: countdown -> playing -> time-outs -> reveal -> next round ...), and a repeat changes nothing. The transitions:
 *
 *   invited    invite_expires_at <= now                       -> closed / invite_expired
 *   room       room_expires_at <= now                         -> closed / room_expired
 *              a player absent > presence_grace_seconds       -> closed / canceled (meta.left = role)
 *   countdown  a player absent                                -> closed / canceled
 *              now >= starts_at                               -> playing / answering (the deadline of round 1 was set by `ready`)
 *   playing    both absent                                    -> closed / both_absent
 *              one absent                                     -> finished / absent, the present one wins
 *              answering, now >= deadline + grace             -> time-out rows for the missing answers, the round is resolved
 *              reveal, now >= phase_ends_at, round < rounds   -> next round / answering, deadline = now + round_seconds
 *              reveal, now >= phase_ends_at, round = rounds   -> finished / completed, the winner by score (a tie: none)
 *
 * The score of a player grows when a round is RESOLVED (the reveal begins) - in the same transaction as the answers that
 * made it - so the poll reads the running score from `battle_participants.score` without a query, and a match that ends
 * early (surrender, absence) counts only the rounds that were resolved. `meta.rounds_played` is written with the end for
 * the same reason. A terminal status puts `active_learner_id = NULL` to both players in the same transaction; the event
 * (`battle.finished` / `battle.closed`) goes after the commit, from the one transaction that made the change.
 *
 * Presence (§8.4): a player is absent when `last_seen_at` (or, before any poll, `accepted_at`) is older than
 * `presence_grace_seconds`; the screens are warned earlier, when it is older than `presence_warn_seconds` ({@see isSilent()}),
 * but the match ends only by the grace. {@see touch()} is the heartbeat: a conditional UPDATE at most once per
 * `heartbeat_write_seconds`, run outside the transaction by the poll (and inside it, before `advance`, by an action - the
 * player who acts is here).
 */
final class BattleClock
{
    /** `meta.left`: the role of the player whose absence canceled the room. */
    public const META_LEFT = 'left';

    /** `meta.voided_reason`: `audio_missing` | `practice_off`. */
    public const META_VOIDED_REASON = 'voided_reason';

    /** `meta.rounds_played`: the rounds that were resolved when the match ended. */
    public const META_ROUNDS_PLAYED = 'rounds_played';

    /** `meta.answered`: `{challenger: n, opponent: n}` - the last round each player has answered (the poll reads it without a query). */
    public const META_ANSWERED = 'answered';

    public const VOIDED_AUDIO_MISSING = 'audio_missing';

    public const VOIDED_PRACTICE_OFF = 'practice_off';

    public function __construct(
        private readonly BattleSettings $settings,
        private readonly ProductEventRecorder $events,
    ) {}

    /** A transition is due: a pure function of the row (with its participants) and `$now` - the poll reads it without a lock. */
    public function isDue(Battle $battle, CarbonImmutable $now): bool
    {
        return match ($battle->status) {
            BattleStatus::Invited => $battle->invite_expires_at->lte($now),
            BattleStatus::Room => ($battle->room_expires_at !== null && $battle->room_expires_at->lte($now)) || $this->firstAbsent($battle, $now) !== null,
            BattleStatus::Countdown => ($battle->starts_at !== null && $battle->starts_at->lte($now)) || $this->firstAbsent($battle, $now) !== null,
            BattleStatus::Playing => $this->firstAbsent($battle, $now) !== null || $this->roundIsDue($battle, $now),
            BattleStatus::Finished, BattleStatus::Closed => false,
        };
    }

    /**
     * Moves the match as far as `$now` allows (see the class docblock). Under `FOR UPDATE` of the row, participants loaded.
     */
    public function advance(Battle $battle, CarbonImmutable $now): BattleTransitionReport
    {
        $changed = false;

        while (true) {
            $report = match ($battle->status) {
                BattleStatus::Invited => $this->tickInvited($battle, $now),
                BattleStatus::Room => $this->tickRoom($battle, $now),
                BattleStatus::Countdown => $this->tickCountdown($battle, $now),
                BattleStatus::Playing => $this->tickPlaying($battle, $now),
                BattleStatus::Finished, BattleStatus::Closed => BattleTransitionReport::nothing(),
            };

            if ($report !== null) {
                return new BattleTransitionReport($changed || $report->changed, $report->finalized);
            }
            $changed = true;
        }
    }

    /** The round takes answers: `answering`, and the deadline plus its grace has not passed (docs/16 §8.5). */
    public function acceptsAnswers(Battle $battle, CarbonImmutable $now): bool
    {
        return $battle->status === BattleStatus::Playing
            && $battle->phase === BattlePhase::Answering
            && $battle->round_deadline_at !== null
            && $now->lt($battle->round_deadline_at->addMilliseconds($this->settings->deadlineGraceMs()));
    }

    /**
     * The round is resolved: the reveal begins, the points of both answers go to the scores (one statement), the version
     * grows. `$answers` - the final answers of the current round (two rows: an answer or a time-out for each player).
     *
     * @param  Collection<int, BattleAnswer>  $answers
     */
    public function resolveRound(Battle $battle, CarbonImmutable $now, Collection $answers): void
    {
        $points = [];
        foreach ($answers as $answer) {
            if ($answer->round === $battle->current_round) {
                $points[$answer->learner_id] = ($points[$answer->learner_id] ?? 0) + $answer->points;
            }
        }
        $this->addScores($battle, $points, $now);

        $this->write($battle, [
            'phase' => BattlePhase::Reveal,
            'phase_ends_at' => $now->addSeconds($this->settings->revealSeconds()),
        ]);
    }

    /**
     * The match ends with a result (`finished`): the winner (null - a draw), both players are let go, the event follows the commit.
     */
    public function finish(Battle $battle, BattleEndReason $reason, ?int $winnerId, CarbonImmutable $now): void
    {
        $roundsPlayed = $this->roundsPlayed($battle);
        $this->release($battle, $now);

        $this->write($battle, [
            'status' => BattleStatus::Finished,
            'end_reason' => $reason,
            'winner_learner_id' => $winnerId,
            'finished_at' => $now,
            'phase' => null,
            'meta' => [...$battle->meta, self::META_ROUNDS_PLAYED => $roundsPlayed],
        ]);

        $startedAt = $battle->starts_at ?? $battle->accepted_at ?? $battle->created_at ?? $now;
        $props = [
            'battle_id' => $battle->id,
            'end_reason' => $reason->value,
            'result' => $winnerId === null ? 'draw' : ($winnerId === $battle->challenger_id ? 'challenger' : 'opponent'),
            'rounds_played' => $roundsPlayed,
            'duration_ms' => max(0, $now->getTimestampMs() - $startedAt->getTimestampMs()),
        ];
        DB::afterCommit(fn () => $this->events->record(ProductEventName::BattleFinished, $props, learner: $this->actor($battle)));
    }

    /**
     * The match ends without a result (`closed`): both players are let go, `$meta` is merged into the row, the event follows the commit.
     *
     * @param  array<string, mixed>  $meta
     */
    public function close(Battle $battle, BattleEndReason $reason, CarbonImmutable $now, array $meta = []): void
    {
        $roundsPlayed = $this->roundsPlayed($battle);
        $this->release($battle, $now);

        $this->write($battle, [
            'status' => BattleStatus::Closed,
            'end_reason' => $reason,
            'finished_at' => $now,
            'phase' => null,
            'meta' => [...$battle->meta, ...$meta, self::META_ROUNDS_PLAYED => $roundsPlayed],
        ]);

        $props = ['battle_id' => $battle->id, 'end_reason' => $reason->value];
        DB::afterCommit(fn () => $this->events->record(ProductEventName::BattleClosed, $props, learner: $this->actor($battle)));
    }

    /**
     * The heartbeat of a player (docs/16 §8.4): `last_seen_at = now`, written by one conditional statement and only when the
     * last write is older than `heartbeat_write_seconds` - at most one write per player per interval, whatever the number of
     * tabs. True when this call wrote. The loaded participant is brought up to date either way.
     */
    public function touch(Battle $battle, BattleParticipant $participant, CarbonImmutable $now): bool
    {
        $threshold = $now->subSeconds($this->settings->heartbeatWriteSeconds());
        if ($participant->last_seen_at !== null && $participant->last_seen_at->gt($threshold)) {
            return false;
        }

        $written = BattleParticipant::query()
            ->whereKey($participant->id)
            ->where(function (Builder $stale) use ($threshold): void {
                $stale->whereNull('last_seen_at')->orWhere('last_seen_at', '<=', $threshold);
            })
            ->update(['last_seen_at' => $now, 'updated_at' => $now]) === 1;

        $participant->last_seen_at = $now;

        return $written;
    }

    /** The player counts as absent: the last presence is older than `presence_grace_seconds` (docs/16 §8.4). */
    public function isAbsent(Battle $battle, BattleParticipant $participant, CarbonImmutable $now): bool
    {
        $seen = $this->lastSeen($battle, $participant);

        return $seen !== null && $seen->lt($now->subSeconds($this->settings->presenceGraceSeconds()));
    }

    /**
     * The player has gone quiet: the last presence is older than `presence_warn_seconds` (docs/16 §8.4). A warning for the screens
     * ("the opponent lost the connection, N s left") that comes well before {@see isAbsent()}; only the latter ends a match.
     */
    public function isSilent(Battle $battle, BattleParticipant $participant, CarbonImmutable $now): bool
    {
        $seen = $this->lastSeen($battle, $participant);

        return $seen !== null && $seen->lt($now->subSeconds($this->settings->presenceWarnSeconds()));
    }

    /** The last presence of a quiet player (what the screens count the grace from); null while the player is here. */
    public function silentSince(Battle $battle, BattleParticipant $participant, CarbonImmutable $now): ?CarbonImmutable
    {
        return $this->isSilent($battle, $participant, $now) ? $this->lastSeen($battle, $participant) : null;
    }

    /** How many rounds the match has resolved so far (a match that is over: `meta.rounds_played`). */
    public function roundsPlayed(Battle $battle): int
    {
        if ($battle->status->isTerminal()) {
            return max(0, (int) ($battle->meta[self::META_ROUNDS_PLAYED] ?? 0));
        }
        if ($battle->status !== BattleStatus::Playing) {
            return 0;
        }

        return $battle->phase === BattlePhase::Reveal ? $battle->current_round : max(0, $battle->current_round - 1);
    }

    /** The player with the higher score, or null for a tie. */
    public function leader(Battle $battle): ?int
    {
        $challenger = $battle->participant($battle->challenger_id)->score;
        $opponent = $battle->participant($battle->opponent_id)->score;

        return $challenger === $opponent ? null : ($challenger > $opponent ? $battle->challenger_id : $battle->opponent_id);
    }

    // ------------------------------------------------------------------ the ticks (null: a step was written, look again)

    private function tickInvited(Battle $battle, CarbonImmutable $now): BattleTransitionReport
    {
        if ($battle->invite_expires_at->lte($now)) {
            $this->close($battle, BattleEndReason::InviteExpired, $now);

            return new BattleTransitionReport(true, true);
        }

        return BattleTransitionReport::nothing();
    }

    private function tickRoom(Battle $battle, CarbonImmutable $now): BattleTransitionReport
    {
        if ($battle->room_expires_at !== null && $battle->room_expires_at->lte($now)) {
            $this->close($battle, BattleEndReason::RoomExpired, $now);

            return new BattleTransitionReport(true, true);
        }

        return $this->cancelIfLeft($battle, $now) ?? BattleTransitionReport::nothing();
    }

    private function tickCountdown(Battle $battle, CarbonImmutable $now): ?BattleTransitionReport
    {
        $left = $this->cancelIfLeft($battle, $now);
        if ($left !== null) {
            return $left;
        }

        if ($battle->starts_at !== null && $battle->starts_at->lte($now)) {
            // The deadline of round 1 stands since `ready` (starts_at + round_seconds): it does not depend on who looks first.
            $this->write($battle, ['status' => BattleStatus::Playing, 'phase' => BattlePhase::Answering]);

            return null;
        }

        return BattleTransitionReport::nothing();
    }

    private function tickPlaying(Battle $battle, CarbonImmutable $now): ?BattleTransitionReport
    {
        $challenger = $battle->participant($battle->challenger_id);
        $opponent = $battle->participant($battle->opponent_id);
        $challengerAbsent = $this->isAbsent($battle, $challenger, $now);
        $opponentAbsent = $this->isAbsent($battle, $opponent, $now);

        if ($challengerAbsent && $opponentAbsent) {
            $this->close($battle, BattleEndReason::BothAbsent, $now);

            return new BattleTransitionReport(true, true);
        }
        if ($challengerAbsent || $opponentAbsent) {
            $this->finish($battle, BattleEndReason::Absent, $challengerAbsent ? $opponent->learner_id : $challenger->learner_id, $now);

            return new BattleTransitionReport(true, true);
        }

        if ($battle->phase === BattlePhase::Answering) {
            if (! $this->roundIsDue($battle, $now)) {
                return BattleTransitionReport::nothing();
            }
            $this->resolveRound($battle, $now, $this->answersWithTimeouts($battle, $now));

            return null;
        }

        if (! $this->roundIsDue($battle, $now)) {
            return BattleTransitionReport::nothing();
        }
        if ($battle->current_round < $this->settings->rounds()) {
            $this->write($battle, [
                'current_round' => $battle->current_round + 1,
                'phase' => BattlePhase::Answering,
                'round_deadline_at' => $now->addSeconds($this->settings->roundSeconds($battle->mode)),
                'phase_ends_at' => null,
            ]);

            return null;
        }

        $this->finish($battle, BattleEndReason::Completed, $this->leader($battle), $now);

        return new BattleTransitionReport(true, true);
    }

    /** A room or a countdown with a player gone: closed / canceled with the role that left. */
    private function cancelIfLeft(Battle $battle, CarbonImmutable $now): ?BattleTransitionReport
    {
        $left = $this->firstAbsent($battle, $now);
        if ($left === null) {
            return null;
        }

        $this->close($battle, BattleEndReason::Canceled, $now, [self::META_LEFT => $left->role->value]);

        return new BattleTransitionReport(true, true);
    }

    // ------------------------------------------------------------------ the pieces

    /** The time of the current phase is up: the deadline plus its grace (answering) or the end of the reveal. */
    private function roundIsDue(Battle $battle, CarbonImmutable $now): bool
    {
        if ($battle->phase === BattlePhase::Answering) {
            return $battle->round_deadline_at !== null && ! $this->acceptsAnswers($battle, $now);
        }

        return $battle->phase_ends_at !== null && $battle->phase_ends_at->lte($now);
    }

    private function firstAbsent(Battle $battle, CarbonImmutable $now): ?BattleParticipant
    {
        foreach ($battle->participants as $participant) {
            if ($this->isAbsent($battle, $participant, $now)) {
                return $participant;
            }
        }

        return null;
    }

    private function lastSeen(Battle $battle, BattleParticipant $participant): ?CarbonImmutable
    {
        return $participant->last_seen_at ?? $battle->accepted_at;
    }

    /**
     * The answers of the current round, with a `timeout` row (0 points, a deterministic uuid v5 request id) written for each
     * player who has none - the deadline has passed.
     *
     * @return Collection<int, BattleAnswer>
     */
    private function answersWithTimeouts(Battle $battle, CarbonImmutable $now): Collection
    {
        /** @var Collection<int, BattleAnswer> $answers */
        $answers = BattleAnswer::query()->where('battle_id', $battle->id)->where('round', $battle->current_round)->get();

        foreach ($battle->participants as $participant) {
            if ($answers->contains(fn (BattleAnswer $answer): bool => $answer->learner_id === $participant->learner_id)) {
                continue;
            }

            $answers->push(BattleAnswer::query()->create([
                'battle_id' => $battle->id,
                'learner_id' => $participant->learner_id,
                'round' => $battle->current_round,
                'request_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "lexiflow:battle:{$battle->id}:round:{$battle->current_round}:learner:{$participant->learner_id}")->toString(),
                'payload' => ['timeout' => true],
                'verdict' => Verdict::Timeout,
                'points' => 0,
                'accepted_at' => $now,
            ]));
        }

        return $answers;
    }

    /**
     * Adds the points of a resolved round to both scores in one statement (the loaded rows follow).
     *
     * @param  array<int, int>  $points  learner id => points of the round
     */
    private function addScores(Battle $battle, array $points, CarbonImmutable $now): void
    {
        $challengerPoints = $points[$battle->challenger_id] ?? 0;
        $opponentPoints = $points[$battle->opponent_id] ?? 0;

        if ($challengerPoints > 0 || $opponentPoints > 0) {
            // Both branches are cast: PostgreSQL would otherwise take two unknown parameters for text and refuse `score + text`.
            DB::update(
                'UPDATE battle_participants SET score = score + (CASE WHEN learner_id = ? THEN CAST(? AS integer) ELSE CAST(? AS integer) END), updated_at = ? WHERE battle_id = ?',
                [$battle->challenger_id, $challengerPoints, $opponentPoints, $now, $battle->id],
            );
        }

        foreach ($battle->participants as $participant) {
            $participant->score += $participant->learner_id === $battle->challenger_id ? $challengerPoints : $opponentPoints;
        }
    }

    /** Both players are let go of the match: `active_learner_id = NULL` (the "one active battle" of docs/16 §2.7). */
    private function release(Battle $battle, CarbonImmutable $now): void
    {
        BattleParticipant::query()->where('battle_id', $battle->id)->update(['active_learner_id' => null, 'updated_at' => $now]);

        foreach ($battle->participants as $participant) {
            $participant->active_learner_id = null;
        }
    }

    /**
     * One UPDATE of the row with the version grown: every change the players can see goes through here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function write(Battle $battle, array $attributes): void
    {
        $battle->forceFill([...$attributes, 'state_version' => $battle->state_version + 1])->save();
    }

    /** The actor of the events of a battle is the challenger (docs/16 §3.4); read after the commit. */
    private function actor(Battle $battle): ?Learner
    {
        return $battle->challenger;
    }
}
