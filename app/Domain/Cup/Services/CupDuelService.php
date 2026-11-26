<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupDuelOutcome;
use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Enums\CupMessageKind;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Cup\Support\CupScoring;
use App\Domain\Engagement\Enums\FriendshipStatus;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Practice\DTO\CupBattlePlan;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeLimits;
use App\Models\Course;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The duels of a cup (docs/33 §5.7, §11.9; D1): a challenge of a participant - to one participant or open to the first who accepts,
 * in the chat or not -, its acceptance into a live battle ({@see BattleService::openForCup()}), the decline, the withdrawal, the expiry,
 * and the result taken into the cup once the battle is over ({@see score()}, run by `ScoreCupDuelJob` after the commit of the battle's
 * end and by `cups:sweep-duels`).
 *
 * The rules (docs/33 §5.7, docs/29, owner's К2, К3, К5):
 *  - both players are participants of the SAME cup; the one who acts passes the fresh check of the membership of a round start
 *    ({@see CupMembershipGate::admitToRound()}); the other one is `active` with a successful check within `stale_ok_hours` (§4.2);
 *    a block in `friendships` in either direction makes the duel unavailable (403 without a reason - a block is invisible);
 *  - a RATED duel: one per participant and day of the cup (`cup_duel_slots (participant_id, day)`); the status is shown to both before
 *    the acceptance and re-decided at it (409 `cup_duel_rating_changed`); free for Free and Pro alike (`battles.limit_exempt`); every
 *    player gets 1 point per OWN right answer (0…5), assigned to `duel_points` of the day of the ACCEPTANCE; the surrender, the absence
 *    or a weak opponent give nothing more; a match nobody answered in (whatever its end) or one the system voided is `void` and
 *    gives the slots back;
 *  - a FRIENDLY match (one of them has played the rated duel of the day): no points of the cup; it spends the Free limit of battles of
 *    the challenger like an ordinary invitation (К5) - checked at the challenge, claimed at the acceptance;
 *  - nothing outside the cup and the battle is written: no league points, daily goal, streak, SRS (К3);
 *  - anti-spam (docs/33 §6.3, §11.9): one open challenge per participant; per day of the cup `daily_challenges` challenges, at most
 *    `pair_daily_challenges` to the same participant, `public_per_participant` / `public_per_chat` in the chat.
 *
 * Locks (docs/33 §11.9, §11.11 п. 3 - one order for the whole cup): `cups → cup_duels → [learners] → battles → battle_participants`
 * and `cup_duels → cups (FOR KEY SHARE) → cup_participants → cup_duel_slots / cup_day_scores`. A public challenge takes `FOR NO KEY
 * UPDATE` of the cup (its daily budget of the chat is decided under it); the acceptance locks the duel, inserts the slots (one
 * statement, by participant id), claims the Free limit under the challenger's `learners` row and opens the battle; the scoring locks the
 * duel, then the cup `FOR KEY SHARE` (its status read under it - no transition of the cup passes while it is held), then both
 * participants by id - the cup BEFORE the participants, since the rows it inserts check their foreign key on the cup. The
 * transactions of a battle never take a lock of the cup: its end only queues the job after the commit. Telegram is asked before any
 * transaction.
 */
final class CupDuelService
{
    public function __construct(
        private readonly CupMembershipGate $gate,
        private readonly BattleService $battles,
        private readonly PracticeAccess $practice,
        private readonly PracticeLimits $limits,
        private readonly FriendshipService $friendships,
        private readonly CupScoreBook $scores,
        private readonly CupStandings $standings,
        private readonly CupOutbox $outbox,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * A challenge (`POST /cups/{id}/duels`): to the participant `$targetParticipantId`, or open to the first who accepts (null); in
     * the chat (`$public`: a row of the outbox `duel:{id}`) or not. A repeated `request_id` of the same participant returns the duel it
     * made (`created = false`), whatever happened to it since.
     *
     * @throws CupException cup_unavailable | cup_not_member | cup_duel_disabled | cup_not_open | cup_not_started | cup_closed
     *                      | cup_paused | cup_round_unavailable | cup_duel_mode_invalid | cup_duel_self | cup_duel_unavailable
     *                      | cup_duel_already_open | cup_duel_limit | cup_unverifiable | cup_check_busy
     * @throws PracticeException practice_mode_disabled | practice_limit_reached (a friendly duel of a Free learner at the limit)
     */
    public function challenge(Learner $by, Cup $cup, ?int $targetParticipantId, bool $public, PracticeMode $mode, string $requestId, ?CarbonImmutable $now = null): CupDuelOutcome
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $requestId = strtolower($requestId);
        $group = $cup->telegramGroup;
        if (! CupAccess::allowsGroup($group)) {
            throw CupException::unavailable();
        }
        $me = $this->participantOf($cup, $by) ?? throw CupException::notMember();

        $repeat = $this->byRequest($me->id, $requestId);
        if ($repeat !== null) {
            return new CupDuelOutcome($repeat, false);
        }

        $this->assertDuelsOn();
        $this->assertRunning($cup);
        $this->assertNotPaused($group);
        $day = $this->currentDay($cup, $now) ?? throw CupException::roundUnavailable();
        if (! $mode->battleable()) {
            throw CupException::duelModeInvalid();
        }
        $this->practice->assertModeEnabled($mode);

        $target = null;
        if ($targetParticipantId !== null) {
            if ($targetParticipantId === $me->id) {
                throw CupException::duelSelf();
            }
            $target = $this->participantWithLearner($targetParticipantId, $cup->id);
            if ($target === null || ! $this->canBeChallenged($target, $now) || $this->blocked($me->learner_id, $target->learner_id)) {
                throw CupException::duelUnavailable();
            }
        }

        $ratedOffered = $this->assertUnderLimits($cup, $me, $target, $public, $day, $now);
        if (! $ratedOffered) {
            $this->limits->assertBattleAllowed($by, $now);
        }

        // Telegram last: every refusal the database can give costs no call.
        $this->gate->admitToRound($cup, $me, $by, $now);

        try {
            /** @var CupDuel|CupException $made */
            $made = DB::transaction(function () use ($cup, $me, $target, $public, $mode, $requestId, $day, $ratedOffered, $now): CupDuel|CupException {
                if ($public) {
                    // The budget of the chat is decided under the row of the cup (FOR NO KEY UPDATE: inserts that only reference the
                    // cup are not held up; a join's FOR SHARE and the transitions of the cup wait a moment).
                    $this->lockCup($cup->id);
                    $refusal = $this->publicRefusal($cup->id, $me->id, $day);
                    if ($refusal !== null) {
                        return $refusal;
                    }
                }

                // forceFill + save: `create()` of a model with `$guarded` asks the schema for its columns first.
                $duel = (new CupDuel)->forceFill([
                    'cup_id' => $cup->id,
                    'day' => $day,
                    'challenger_participant_id' => $me->id,
                    'opponent_participant_id' => $target?->id,
                    'is_public' => $public,
                    'rated_offered' => $ratedOffered,
                    'rated' => null,
                    'mode' => $mode,
                    'status' => CupDuelStatus::Open,
                    'open_challenger_id' => $me->id,
                    'request_id' => $requestId,
                    'expires_at' => $now->addMinutes(max(1, (int) config('cup.duel.challenge_minutes', 10))),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $duel->save();
                if ($public) {
                    $this->outbox->enqueueMany([['cup_id' => $cup->id, 'dedupe_key' => CupOutbox::duelKey($duel->id)]], CupMessageKind::Duel, $now);
                }

                return $duel;
            });
        } catch (UniqueConstraintViolationException) {
            // The index decided: the same request made it a moment ago, or another open challenge of mine landed first.
            $repeat = $this->byRequest($me->id, $requestId);
            if ($repeat !== null) {
                return new CupDuelOutcome($repeat, false);
            }
            $open = CupDuel::query()->where('open_challenger_id', $me->id)->value('id');

            throw $open !== null ? CupException::duelAlreadyOpen((int) $open) : new LogicException('The challenge hit a unique index nobody holds.');
        }
        if ($made instanceof CupException) {
            throw $made;
        }

        $this->events->record(ProductEventName::CupDuelChallenged, [
            'cup_id' => $cup->id,
            'duel_id' => $made->id,
            'public' => $public,
            'addressed' => $target !== null,
            'rated_offered' => $ratedOffered,
            'mode' => $mode->value,
        ], learner: $by);

        return new CupDuelOutcome($made, true);
    }

    /**
     * The acceptance (`POST /cups/{id}/duels/{duel}/accept {expect_rated}`): the addressee, or any other participant for an open
     * challenge. `$expectRated` - what the screen showed; the server decides again and refuses a difference (409
     * `cup_duel_rating_changed {rated}`: the screen shows the new status and the learner accepts once more). The match is opened at once
     * in its room ({@see BattleService::openForCup()}); a player busy with another match - 409 `battle_busy {battle_id}` (mine) or
     * `cup_duel_opponent_busy` (the challenger's): no second match for the pair or a player, two crossing acceptances make one match.
     * A repeated acceptance by the same participant returns the duel (`created = false`).
     *
     * @throws CupException cup_unavailable | cup_duel_not_found | cup_duel_own | cup_duel_closed | cup_duel_disabled | cup_paused
     *                      | cup_duel_unavailable | cup_duel_rating_changed | cup_duel_opponent_busy | cup_not_member | cup_unverifiable | cup_check_busy
     * @throws BattleException battle_busy | battle_disabled | battle_material_unavailable
     * @throws PracticeException practice_mode_disabled
     */
    public function accept(Learner $by, int $duelId, bool $expectRated, ?CarbonImmutable $now = null): CupDuelOutcome
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$duel, $cup, $me] = $this->locate($by, $duelId);

        if ($duel->status === CupDuelStatus::Accepted && $duel->opponent_participant_id === $me->id) {
            return new CupDuelOutcome($duel, false);
        }
        if ($duel->challenger_participant_id === $me->id) {
            throw CupException::duelOwn();
        }
        if ($duel->opponent_participant_id !== null && $duel->opponent_participant_id !== $me->id) {
            throw CupException::duelNotFound();
        }
        if ($duel->status !== CupDuelStatus::Open) {
            throw CupException::duelClosed($duel->status->value, $duel->end_reason?->value);
        }
        $this->assertDuelsOn();
        if ($cup->status !== CupStatus::Running) {
            throw $this->closeOne($duel, CupDuelStatus::Canceled, CupDuelEndReason::CupClosed, $now);
        }
        $this->assertNotPaused($cup->telegramGroup);
        $day = $this->currentDay($cup, $now);
        if ($day === null) {
            throw $this->closeOne($duel, CupDuelStatus::Expired, CupDuelEndReason::CupOver, $now);
        }
        if ($duel->expires_at->lte($now)) {
            throw $this->closeOne($duel, CupDuelStatus::Expired, CupDuelEndReason::Expired, $now);
        }

        $challenger = $this->participantWithLearner($duel->challenger_participant_id, $duel->cup_id)
            ?? throw new LogicException('The challenger of a duel has no row.');
        if (! $this->canBeChallenged($challenger, $now)) {
            throw $this->closeOne($duel, CupDuelStatus::Canceled, CupDuelEndReason::Left, $now);
        }
        if ($this->blocked($challenger->learner_id, $me->learner_id)) {
            throw CupException::duelUnavailable();
        }

        $this->gate->admitToRound($cup, $me, $by, $now);

        $rated = $this->freeOfSlots([$challenger->id, $me->id], $day);
        if ($rated !== $expectRated) {
            throw CupException::duelRatingChanged($rated);
        }

        /** @var Learner $challengerLearner */
        $challengerLearner = Learner::query()->findOrFail($challenger->learner_id);
        /** @var Course $course */
        $course = Course::query()->findOrFail($cup->course_id);
        try {
            $plan = $this->battles->prepareForCup($challengerLearner, $by, $duel->mode, $course);
        } catch (BattleException $e) {
            if ($e->errorCode === BattleException::MATERIAL_UNAVAILABLE) {
                $this->closeOne($duel, CupDuelStatus::Canceled, CupDuelEndReason::Material, $now);
            }

            throw $e;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                /** @var array{0: CupDuel, 1: bool}|CupException $outcome */
                $outcome = DB::transaction(fn (): array|CupException => $this->acceptLocked($duel->id, $me, $challengerLearner, $plan, $rated, $day, $now));
            } catch (UniqueConstraintViolationException) {
                // A player is busy with another match, or the pair has one (the battle's own indexes decided, the slots went back with
                // the rollback). A dead match that kept one of them busy is closed by the clock on the way - then once more.
                $mine = $this->battles->busyBattleOf($by, $now) ?? $this->battles->openBattleOfPairOf($by, $challengerLearner, $now);
                if ($mine !== null) {
                    throw BattleException::busy($mine);
                }
                if ($this->battles->busyBattleOf($challengerLearner, $now) !== null) {
                    throw CupException::duelOpponentBusy();
                }

                continue;
            }
            if ($outcome instanceof CupException) {
                throw $outcome;
            }

            [$accepted, $created] = $outcome;
            if ($created) {
                $this->events->record(ProductEventName::CupDuelAccepted, [
                    'cup_id' => $cup->id,
                    'duel_id' => $accepted->id,
                    'battle_id' => $accepted->battle_id,
                    'rated' => (bool) $accepted->rated,
                ], learner: $by);
            }

            return new CupDuelOutcome($accepted, $created);
        }

        throw new LogicException('Could not accept the duel: the battles of the players keep changing under us.');
    }

    /**
     * The addressee says no (`POST …/decline`) → `declined`; the public message is edited, never a new one. A repeat is a no-op.
     *
     * @throws CupException cup_unavailable | cup_duel_not_found | cup_duel_not_recipient | cup_duel_closed
     */
    public function decline(Learner $by, int $duelId, ?CarbonImmutable $now = null): CupDuel
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$duel, , $me] = $this->locate($by, $duelId);
        if ($duel->opponent_participant_id !== $me->id) {
            throw $duel->challenger_participant_id === $me->id || $duel->opponent_participant_id === null
                ? CupException::duelNotRecipient()
                : CupException::duelNotFound();
        }

        return $this->closeByPlayer($duel->id, CupDuelStatus::Declined, CupDuelEndReason::Declined, $now);
    }

    /**
     * The challenger withdraws an open challenge (`POST …/cancel`) → `canceled`; the public message is edited. A repeat is a no-op.
     *
     * @throws CupException cup_unavailable | cup_duel_not_found | cup_duel_closed
     */
    public function cancel(Learner $by, int $duelId, ?CarbonImmutable $now = null): CupDuel
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        [$duel, , $me] = $this->locate($by, $duelId);
        if ($duel->challenger_participant_id !== $me->id) {
            throw CupException::duelNotFound();
        }

        return $this->closeByPlayer($duel->id, CupDuelStatus::Canceled, CupDuelEndReason::Challenger, $now);
    }

    /**
     * The result of an accepted duel whose battle is over (docs/33 §5.7 «Начисление»), exactly once: under `FOR UPDATE` of the duel a
     * duel that is not `accepted` any more is left alone (a second job, the sweeper, the finish of the cup). Returns the new status, or
     * null when nothing was done (not accepted, or the battle is still on).
     *
     *  - the battle is gone, the system voided it (`end_reason = voided`: a clip missing, the practice switched off - answers or not),
     *    or nobody answered anything (no `battle_answers` other than `timeout`), whatever the end - a surrender in the countdown too →
     *    `void`, the slots go;
     *  - else each player's points = his own `correct` answers (≤ `cups.duel_tasks`); the winner of the battle is kept for the history;
     *    a rated duel writes them into `duel_points` of the day of the acceptance - under the locks of both participants, and only while
     *    the cup is `running` (read under `FOR KEY SHARE` of the cup, taken before the participants): a result that comes after the
     *    places of the finish changes nothing (`done` with `end_reason = cup_closed`); a friendly one writes nothing of the cup.
     */
    public function score(int $duelId, ?CarbonImmutable $now = null): ?CupDuelStatus
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();

        /** @var array{0: CupDuel, 1: bool}|null $done */
        $done = DB::transaction(function () use ($duelId, $now): ?array {
            /** @var CupDuel|null $duel */
            $duel = CupDuel::query()->whereKey($duelId)->lockForUpdate()->first();
            if ($duel === null || $duel->status !== CupDuelStatus::Accepted) {
                return null;
            }
            $battle = $duel->battle_id === null ? null : $this->battleResult($duel->battle_id);
            if ($battle !== null && ! $battle['terminal']) {
                return null;
            }

            if ($battle === null) {
                $this->void($duel, CupDuelEndReason::BattleGone, $now);

                return [$duel, false];
            }
            // The system called the match off (a clip missing, the practice switched off): nobody's doing - void, whatever was answered.
            if ($battle['voided']) {
                $this->void($duel, CupDuelEndReason::BattleVoided, $now);

                return [$duel, false];
            }
            // Nobody answered anything - whatever the end (a surrender in the countdown, both absent, the room expired): void, so that
            // nobody can burn the rated duel of the day of another by giving up before the first task.
            if ($battle['given'] === 0) {
                $this->void($duel, CupDuelEndReason::NoAnswers, $now);

                return [$duel, false];
            }

            $winner = match ($battle['winner']) {
                $battle['challenger'] => $duel->challenger_participant_id,
                $battle['opponent'] => $duel->opponent_participant_id,
                default => null,
            };

            $credited = false;
            $reason = null;
            $rated = $duel->rated === true && $duel->opponent_participant_id !== null && $duel->accepted_day !== null;
            // The cup `FOR KEY SHARE` BEFORE the participants (docs/33 §11.9.2 п. 1): the rows this transaction inserts (the first day
            // row of a duel-only day, the edit of a public challenge) take this very lock through their foreign key - taken after the
            // participants it would wait for a finish that holds the cup and waits for them (40P01). Held, it keeps every transition of
            // the cup (all take `FOR UPDATE`) out until the commit, so the status read here is the status the points are written under;
            // after a finish it waited for, READ COMMITTED returns the new row - `finished`.
            $cupQuery = DB::table('cups')->where('id', $duel->cup_id);
            $cup = (DB::getDriverName() === 'pgsql' ? $cupQuery->lock('for key share') : $cupQuery)->first(['status', 'duel_tasks', 'counted_days']);
            if ($rated) {
                $ids = [$duel->challenger_participant_id, $duel->opponent_participant_id];
                sort($ids);
                DB::table('cup_participants')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
            }
            $cap = max(0, min(CupScoring::DUEL_CAP, (int) ($cup->duel_tasks ?? CupScoring::DUEL_CAP)));
            $challengerPoints = min($cap, $battle['challenger_correct']);
            $opponentPoints = min($cap, $battle['opponent_correct']);

            if ($rated) {
                if (($cup->status ?? null) === CupStatus::Running->value) {
                    $countedDays = (int) ($cup->counted_days ?? 5);
                    $this->scores->setDuelPoints($duel->cup_id, $duel->challenger_participant_id, $duel->accepted_day, $challengerPoints, $countedDays, $now);
                    $this->scores->setDuelPoints($duel->cup_id, $duel->opponent_participant_id, $duel->accepted_day, $opponentPoints, $countedDays, $now);
                    $this->standings->forgetAfterCommit($duel->cup_id);
                    $credited = true;
                } else {
                    $reason = CupDuelEndReason::CupClosed;
                }
            }

            $duel->forceFill([
                'status' => CupDuelStatus::Done,
                'end_reason' => $reason,
                'challenger_points' => $challengerPoints,
                'opponent_points' => $opponentPoints,
                'winner_participant_id' => $winner,
                'scored_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
            ])->save();
            $this->editPublic($duel, $now);

            return [$duel, $credited];
        });

        if ($done === null) {
            return null;
        }
        [$duel, $credited] = $done;
        if ($duel->status === CupDuelStatus::Void) {
            $this->events->record(ProductEventName::CupDuelVoid, ['cup_id' => $duel->cup_id, 'duel_id' => $duel->id, 'reason' => $duel->end_reason?->value]);
        } else {
            $this->events->record(ProductEventName::CupDuelScored, [
                'cup_id' => $duel->cup_id,
                'duel_id' => $duel->id,
                'rated' => (bool) $duel->rated,
                'credited' => $credited,
                'challenger_points' => (int) $duel->challenger_points,
                'opponent_points' => (int) $duel->opponent_points,
            ]);
        }

        return $duel->status;
    }

    /**
     * `cups:sweep-duels` (every minute): the open challenges past their time → `expired` (ONE statement for the batch, the edits of the
     * public ones in one more), then the accepted duels whose battle is over (or gone) and that no job has scored → {@see score()}, one
     * transaction each. Idempotent: a second run, or a run next to the jobs, finds nothing to do twice.
     *
     * @return array{expired: int, scored: int, void: int, failed: int}
     */
    public function sweep(?CarbonImmutable $now = null, ?int $batch = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $batch ??= max(1, (int) config('cup.duel.sweep_batch', 200));

        $report = ['expired' => 0, 'scored' => 0, 'void' => 0, 'failed' => 0];
        try {
            $report['expired'] = $this->expireDue($now, $batch);
        } catch (Throwable $e) {
            report($e);
            $report['failed']++;
        }

        $ids = DB::table('cup_duels as d')
            ->leftJoin('battles as b', 'b.id', '=', 'd.battle_id')
            ->where('d.status', CupDuelStatus::Accepted->value)
            ->where(fn ($q) => $q->whereNull('b.id')->orWhereIn('b.status', [BattleStatus::Finished->value, BattleStatus::Closed->value]))
            ->orderBy('d.accepted_at')
            ->limit($batch)
            ->pluck('d.id');

        foreach ($ids as $id) {
            $this->scoreGuarded((int) $id, $now, $report);
        }

        return $report;
    }

    /**
     * Before the finish of a cup (docs/33 §5.6 «сначала добор очков дуэлей»): every accepted duel of the cup whose battle is over is
     * scored now, so its points are in the places. Called by the scheduler right before {@see CupLifecycleService::finish()}.
     */
    public function settleCup(int $cupId, ?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->startOfSecond();
        $ids = DB::table('cup_duels as d')
            ->leftJoin('battles as b', 'b.id', '=', 'd.battle_id')
            ->where('d.cup_id', $cupId)
            ->where('d.status', CupDuelStatus::Accepted->value)
            ->where(fn ($q) => $q->whereNull('b.id')->orWhereIn('b.status', [BattleStatus::Finished->value, BattleStatus::Closed->value]))
            ->pluck('d.id');

        $report = ['expired' => 0, 'scored' => 0, 'void' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $this->scoreGuarded((int) $id, $now, $report);
        }

        return $report['scored'] + $report['void'];
    }

    /** The open challenges past their time → `expired` in ONE statement (a batch); the public ones get their edit. Returns how many. */
    public function expireDue(CarbonImmutable $now, int $batch): int
    {
        $at = $now->format('Y-m-d H:i:s');
        $rows = DB::select(
            'UPDATE cup_duels SET status = ?, end_reason = ?, open_challenger_id = NULL, closed_at = ?, updated_at = ?
             WHERE id IN (SELECT id FROM cup_duels WHERE status = ? AND expires_at <= ? ORDER BY expires_at LIMIT ?) AND status = ?
             RETURNING id, cup_id, is_public',
            [
                CupDuelStatus::Expired->value, CupDuelEndReason::Expired->value, $at, $at,
                CupDuelStatus::Open->value, $at, max(1, $batch), CupDuelStatus::Open->value,
            ],
        );
        $this->editPublicRows($rows, $now);

        return count($rows);
    }

    // ------------------------------------------------------------------ the pieces

    /**
     * The acceptance under `FOR UPDATE` of the duel (see {@see accept()}). A refusal that must keep a change (the duel closed on the
     * way) is RETURNED and thrown after the commit; one that must undo the slots is thrown here (the transaction rolls back).
     *
     * @return array{0: CupDuel, 1: bool}|CupException
     */
    private function acceptLocked(int $duelId, CupParticipant $me, Learner $challengerLearner, CupBattlePlan $plan, bool $rated, int $day, CarbonImmutable $now): array|CupException
    {
        /** @var CupDuel|null $duel */
        $duel = CupDuel::query()->whereKey($duelId)->lockForUpdate()->first();
        if ($duel === null) {
            return CupException::duelNotFound();
        }
        if ($duel->status === CupDuelStatus::Accepted && $duel->opponent_participant_id === $me->id) {
            return [$duel, false];
        }
        if ($duel->status !== CupDuelStatus::Open) {
            return CupException::duelClosed($duel->status->value, $duel->end_reason?->value);
        }
        if ($duel->expires_at->lte($now)) {
            $this->close($duel, CupDuelStatus::Expired, CupDuelEndReason::Expired, $now);

            return CupException::duelClosed(CupDuelStatus::Expired->value, CupDuelEndReason::Expired->value);
        }

        if ($rated) {
            // Both slots in ONE statement, by participant id (two acceptances that share a player wait for each other, never cross);
            // a slot taken in the meantime is the answer: the duel can only be friendly now - nothing of this transaction stays.
            $ids = [$duel->challenger_participant_id, $me->id];
            sort($ids);
            $at = $now->format('Y-m-d H:i:s');
            $taken = DB::select(
                'INSERT INTO cup_duel_slots (cup_id, participant_id, day, cup_duel_id, created_at) VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)
                 ON CONFLICT (participant_id, day) DO NOTHING RETURNING participant_id',
                [$duel->cup_id, $ids[0], $day, $duel->id, $at, $duel->cup_id, $ids[1], $day, $duel->id, $at],
            );
            if (count($taken) < 2) {
                throw CupException::duelRatingChanged(false);
            }
        } else {
            // A friendly duel spends the Free limit of the challenger like an ordinary invitation (К5), under his `learners` row.
            $refusal = $this->limits->claimBattle($challengerLearner, $now);
            if ($refusal !== null) {
                $this->close($duel, CupDuelStatus::Canceled, CupDuelEndReason::ChallengerLimit, $now);

                return CupException::duelClosed(CupDuelStatus::Canceled->value, CupDuelEndReason::ChallengerLimit->value);
            }
        }

        $battle = $this->battles->openForCup($plan, $duel->id, $rated, $now);

        $duel->forceFill([
            'status' => CupDuelStatus::Accepted,
            'opponent_participant_id' => $me->id,
            'rated' => $rated,
            'open_challenger_id' => null,
            'battle_id' => $battle->id,
            'accepted_at' => $now,
            'accepted_day' => $day,
            'updated_at' => $now,
        ])->save();
        if ($duel->is_public) {
            // The message in the chat loses «Принять вызов» at once: «Дуэль идёт: A — B» (docs/33 §6.1) - an edit, never a new message.
            $this->outbox->enqueueMany([['cup_id' => $duel->cup_id, 'dedupe_key' => CupOutbox::duelAcceptedKey($duel->id)]], CupMessageKind::Duel, $now);
        }

        return [$duel, true];
    }

    /** Declined / canceled by a player under the lock of the duel; the same status again is a no-op. */
    private function closeByPlayer(int $duelId, CupDuelStatus $status, CupDuelEndReason $reason, CarbonImmutable $now): CupDuel
    {
        /** @var CupDuel|CupException $outcome */
        $outcome = DB::transaction(function () use ($duelId, $status, $reason, $now): CupDuel|CupException {
            /** @var CupDuel|null $duel */
            $duel = CupDuel::query()->whereKey($duelId)->lockForUpdate()->first();
            if ($duel === null) {
                return CupException::duelNotFound();
            }
            if ($duel->status === $status && $duel->end_reason === $reason) {
                return $duel;
            }
            if ($duel->status !== CupDuelStatus::Open) {
                return CupException::duelClosed($duel->status->value, $duel->end_reason?->value);
            }
            if ($duel->expires_at->lte($now)) {
                $this->close($duel, CupDuelStatus::Expired, CupDuelEndReason::Expired, $now);

                return CupException::duelClosed(CupDuelStatus::Expired->value, CupDuelEndReason::Expired->value);
            }
            $this->close($duel, $status, $reason, $now);

            return $duel;
        });
        if ($outcome instanceof CupException) {
            throw $outcome;
        }

        return $outcome;
    }

    /** An open duel (locked) is closed: the row and, for a public challenge, the edit of its message. */
    private function close(CupDuel $duel, CupDuelStatus $status, CupDuelEndReason $reason, CarbonImmutable $now): void
    {
        $duel->forceFill([
            'status' => $status,
            'end_reason' => $reason,
            'open_challenger_id' => null,
            'closed_at' => $now,
            'updated_at' => $now,
        ])->save();
        $this->editPublic($duel, $now);
    }

    /**
     * An open duel found closed on the way of an acceptance (the cup is over, the challenger left, the time is up): ONE conditional
     * statement outside any transaction (a racing acceptance that holds the row wins; this then changes nothing). Returns the refusal.
     */
    private function closeOne(CupDuel $duel, CupDuelStatus $status, CupDuelEndReason $reason, CarbonImmutable $now): CupException
    {
        $this->closeOpen($duel->id, $status, $reason, $now);

        return CupException::duelClosed($status->value, $reason->value);
    }

    /** The conditional close of an open duel (one statement, the edit of a public one); true when this call closed it. */
    private function closeOpen(int $duelId, CupDuelStatus $status, CupDuelEndReason $reason, CarbonImmutable $now): bool
    {
        $at = $now->format('Y-m-d H:i:s');
        $rows = DB::select(
            'UPDATE cup_duels SET status = ?, end_reason = ?, open_challenger_id = NULL, closed_at = ?, updated_at = ? WHERE id = ? AND status = ?
             RETURNING id, cup_id, is_public',
            [$status->value, $reason->value, $at, $at, $duelId, CupDuelStatus::Open->value],
        );
        $this->editPublicRows($rows, $now);

        return $rows !== [];
    }

    /** An accepted duel whose match never really happened: `void`, the slots of the day are free again (locked). */
    private function void(CupDuel $duel, CupDuelEndReason $reason, CarbonImmutable $now): void
    {
        DB::table('cup_duel_slots')->where('cup_duel_id', $duel->id)->delete();
        $duel->forceFill([
            'status' => CupDuelStatus::Void,
            'end_reason' => $reason,
            'scored_at' => $now,
            'closed_at' => $now,
            'updated_at' => $now,
        ])->save();
        $this->editPublic($duel, $now);
    }

    private function editPublic(CupDuel $duel, CarbonImmutable $now): void
    {
        if ($duel->is_public) {
            $this->outbox->enqueueMany([['cup_id' => $duel->cup_id, 'dedupe_key' => CupOutbox::duelEndKey($duel->id)]], CupMessageKind::Duel, $now);
        }
    }

    /** @param array<int, \stdClass> $rows rows `{id, cup_id, is_public}` of `UPDATE … RETURNING` */
    private function editPublicRows(array $rows, CarbonImmutable $now): void
    {
        $edits = [];
        foreach ($rows as $row) {
            $values = (array) $row;
            if ((bool) ($values['is_public'] ?? false)) {
                $edits[] = ['cup_id' => (int) $values['cup_id'], 'dedupe_key' => CupOutbox::duelEndKey((int) $values['id'])];
            }
        }
        $this->outbox->enqueueMany($edits, CupMessageKind::Duel, $now);
    }

    /**
     * The end of the match and its answers in ONE statement (the battle by its PK, the answers by the unique `(battle_id, round,
     * learner_id)`): `terminal` / `voided` (closed by the system), the players and the winner, each player's right answers, and `given` - every answer of
     * either player that is not a time-out. Null when the battle is gone.
     *
     * @return array{terminal: bool, voided: bool, challenger: int, opponent: int, winner: int|null, challenger_correct: int, opponent_correct: int, given: int}|null
     */
    private function battleResult(int $battleId): ?array
    {
        $row = DB::selectOne(
            "SELECT b.status, b.end_reason, b.challenger_id, b.opponent_id, b.winner_learner_id,
                (SELECT COUNT(*) FROM battle_answers a WHERE a.battle_id = b.id AND a.learner_id = b.challenger_id AND a.verdict = 'correct') AS challenger_correct,
                (SELECT COUNT(*) FROM battle_answers a WHERE a.battle_id = b.id AND a.learner_id = b.opponent_id AND a.verdict = 'correct') AS opponent_correct,
                (SELECT COUNT(*) FROM battle_answers a WHERE a.battle_id = b.id AND a.verdict <> 'timeout') AS given
             FROM battles b WHERE b.id = ?",
            [$battleId],
        );
        if ($row === null) {
            return null;
        }
        $status = BattleStatus::from((string) $row->status);

        return [
            'terminal' => $status->isTerminal(),
            'voided' => $status === BattleStatus::Closed && $row->end_reason === BattleEndReason::Voided->value,
            'challenger' => (int) $row->challenger_id,
            'opponent' => (int) $row->opponent_id,
            'winner' => $row->winner_learner_id === null ? null : (int) $row->winner_learner_id,
            'challenger_correct' => (int) $row->challenger_correct,
            'opponent_correct' => (int) $row->opponent_correct,
            'given' => (int) $row->given,
        ];
    }

    /** @param array{expired: int, scored: int, void: int, failed: int} $report */
    private function scoreGuarded(int $duelId, CarbonImmutable $now, array &$report): void
    {
        try {
            $status = $this->score($duelId, $now);
            if ($status === CupDuelStatus::Done) {
                $report['scored']++;
            } elseif ($status === CupDuelStatus::Void) {
                $report['void']++;
            }
        } catch (Throwable $e) {
            $report['failed']++;
            Log::warning('cup.duel_score_failed', ['cup_duel_id' => $duelId, 'exception' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            report($e);
        }
    }

    /**
     * The limits of a new challenge and the rated status it offers, in ONE statement over the duels of the cup's day (`(cup_id, day)`),
     * the open challenge of the participant (unique `open_challenger_id`) and the slots of the two (unique `(participant_id, day)`). An
     * open challenge of mine whose time is up (the sweeper has not come yet) is expired on the way. Returns `rated_offered`: neither of
     * the two has played the rated duel of the day (an open challenge: the challenger has not).
     *
     * @throws CupException cup_duel_already_open | cup_duel_limit
     */
    private function assertUnderLimits(Cup $cup, CupParticipant $me, ?CupParticipant $target, bool $public, int $day, CarbonImmutable $now): bool
    {
        $targetId = $target->id ?? 0;
        $row = DB::selectOne(
            'SELECT
                COALESCE(SUM(CASE WHEN challenger_participant_id = ? THEN 1 ELSE 0 END), 0) AS mine,
                COALESCE(SUM(CASE WHEN challenger_participant_id = ? AND opponent_participant_id = ? THEN 1 ELSE 0 END), 0) AS pair,
                COALESCE(SUM(CASE WHEN is_public AND challenger_participant_id = ? THEN 1 ELSE 0 END), 0) AS public_mine,
                COALESCE(SUM(CASE WHEN is_public THEN 1 ELSE 0 END), 0) AS public_all,
                (SELECT o.id FROM cup_duels o WHERE o.open_challenger_id = ?) AS open_id,
                (SELECT o.expires_at FROM cup_duels o WHERE o.open_challenger_id = ?) AS open_expires_at,
                (SELECT COUNT(*) FROM cup_duel_slots s WHERE s.participant_id = ? AND s.day = ?) AS my_slot,
                (SELECT COUNT(*) FROM cup_duel_slots s WHERE s.participant_id = ? AND s.day = ?) AS target_slot
             FROM cup_duels WHERE cup_id = ? AND day = ?',
            [$me->id, $me->id, $targetId, $me->id, $me->id, $me->id, $me->id, $day, $targetId, $day, $cup->id, $day],
        );
        if ($row === null) {
            throw new LogicException('An aggregate returned no row.');
        }

        if ($row->open_id !== null) {
            $expired = $row->open_expires_at !== null && CarbonImmutable::parse((string) $row->open_expires_at, 'UTC')->lte($now);
            if (! $expired || ! $this->closeOpen((int) $row->open_id, CupDuelStatus::Expired, CupDuelEndReason::Expired, $now)) {
                throw CupException::duelAlreadyOpen((int) $row->open_id);
            }
        }

        $daily = max(1, (int) config('cup.duel.daily_challenges', 20));
        if ((int) $row->mine >= $daily) {
            throw CupException::duelLimit(CupException::LIMIT_DAILY, $daily);
        }
        $pair = max(1, (int) config('cup.duel.pair_daily_challenges', 3));
        if ($target !== null && (int) $row->pair >= $pair) {
            throw CupException::duelLimit(CupException::LIMIT_PAIR, $pair);
        }
        if ($public) {
            $refusal = $this->publicLimit((int) $row->public_mine, (int) $row->public_all);
            if ($refusal !== null) {
                throw $refusal;
            }
        }

        return (int) $row->my_slot === 0 && ($target === null || (int) $row->target_slot === 0);
    }

    /** The public budget again, under the lock of the cup (one statement). */
    private function publicRefusal(int $cupId, int $participantId, int $day): ?CupException
    {
        $row = DB::selectOne(
            'SELECT COALESCE(SUM(CASE WHEN challenger_participant_id = ? THEN 1 ELSE 0 END), 0) AS public_mine, COUNT(*) AS public_all
             FROM cup_duels WHERE cup_id = ? AND day = ? AND is_public',
            [$participantId, $cupId, $day],
        );

        return $this->publicLimit((int) ($row->public_mine ?? 0), (int) ($row->public_all ?? 0));
    }

    private function publicLimit(int $mine, int $all): ?CupException
    {
        $perParticipant = max(0, (int) config('cup.duel.public_per_participant', 3));
        if ($mine >= $perParticipant) {
            return CupException::duelLimit(CupException::LIMIT_PUBLIC_PARTICIPANT, $perParticipant);
        }
        $perChat = max(0, (int) config('cup.duel.public_per_chat', 10));
        if ($all >= $perChat) {
            return CupException::duelLimit(CupException::LIMIT_PUBLIC_CHAT, $perChat);
        }

        return null;
    }

    /**
     * Neither participant has the rated slot of the day (one statement by the unique `(participant_id, day)`).
     *
     * @param  list<int>  $ids
     */
    private function freeOfSlots(array $ids, int $day): bool
    {
        return ! DB::table('cup_duel_slots')->whereIn('participant_id', $ids)->where('day', $day)->exists();
    }

    /**
     * The duel, its cup (with the group) and the participant of the learner in it. A missing duel, a stranger to its cup - the same 404.
     *
     * @return array{0: CupDuel, 1: Cup, 2: CupParticipant}
     *
     * @throws CupException cup_duel_not_found | cup_unavailable
     */
    private function locate(Learner $by, int $duelId): array
    {
        /** @var CupDuel|null $duel */
        $duel = CupDuel::query()->find($duelId);
        if ($duel === null) {
            throw CupException::duelNotFound();
        }
        /** @var Cup $cup */
        $cup = Cup::query()->with('telegramGroup')->findOrFail($duel->cup_id);
        $me = $this->participantOf($cup, $by) ?? throw CupException::duelNotFound();
        if (! CupAccess::allowsGroup($cup->telegramGroup)) {
            throw CupException::unavailable();
        }

        return [$duel, $cup, $me];
    }

    private function participantOf(Cup $cup, Learner $learner): ?CupParticipant
    {
        /** @var CupParticipant|null $participant */
        $participant = CupParticipant::query()->where('cup_id', $cup->id)->where('learner_id', $learner->id)->first();

        return $participant;
    }

    private function byRequest(int $participantId, string $requestId): ?CupDuel
    {
        /** @var CupDuel|null $duel */
        $duel = CupDuel::query()->where('challenger_participant_id', $participantId)->where('request_id', $requestId)->first();

        return $duel;
    }

    /**
     * The participant of the cup by id with the two marks of his learner a duel needs (`x_learner_blocked`: blocked by the platform or
     * deleted) - ONE statement: the PK of the participant, the PK of the learner (a LEFT JOIN without the scope of the soft delete: a
     * deleted learner is a mark, not a missing row).
     */
    private function participantWithLearner(int $participantId, int $cupId): ?CupParticipant
    {
        /** @var CupParticipant|null $participant */
        $participant = CupParticipant::query()
            ->leftJoin('learners as xl', 'xl.id', '=', 'cup_participants.learner_id')
            ->where('cup_participants.id', $participantId)
            ->where('cup_participants.cup_id', $cupId)
            ->select('cup_participants.*')
            ->selectRaw('CASE WHEN xl.id IS NULL OR xl.blocked_at IS NOT NULL OR xl.deleted_at IS NOT NULL THEN 1 ELSE 0 END AS x_learner_blocked')
            ->first();

        return $participant;
    }

    /**
     * The second player of a duel (docs/33 §4.2): `active`, with a successful check of the membership within `stale_ok_hours`, and a
     * learner neither blocked by the platform nor deleted (the mark of {@see participantWithLearner()}) - the database, never a call to
     * Telegram about somebody who did not act.
     */
    private function canBeChallenged(CupParticipant $participant, CarbonImmutable $now): bool
    {
        return (int) ($participant->getAttribute('x_learner_blocked') ?? 0) === 0
            && $participant->status === CupParticipantStatus::Active
            && $participant->membership_checked_at !== null
            && $participant->membership_checked_at->gte($now->subHours(max(0, (int) config('cup.membership.stale_ok_hours', 24))));
    }

    /** A block in `friendships` in either direction (docs/33 §0: it forbids a duel of the cup between these two). */
    private function blocked(int $a, int $b): bool
    {
        return $this->friendships->between($a, $b)?->status === FriendshipStatus::Blocked->value;
    }

    /**
     * The duels are on: the switch of the cup, the battles, and a practice that is not off (the sweeper of the battles voids every
     * match when it is). No query - the HTTP layer asks it before anything is read (D2: one answer whatever the cup or the duel).
     */
    public function enabled(): bool
    {
        return (bool) config('cup.duels', false) && $this->practice->battleEnabled() && ! $this->practice->isOff();
    }

    /** @throws CupException cup_duel_disabled */
    private function assertDuelsOn(): void
    {
        if (! $this->enabled()) {
            throw CupException::duelDisabled();
        }
    }

    /** @throws CupException cup_not_open | cup_not_started | cup_closed */
    private function assertRunning(Cup $cup): void
    {
        match ($cup->status) {
            CupStatus::Draft => throw CupException::notOpen(),
            CupStatus::Scheduled => throw CupException::notStarted(),
            CupStatus::Finished, CupStatus::Canceled => throw CupException::closed(),
            CupStatus::Running => null,
        };
    }

    /** @throws CupException cup_paused */
    private function assertNotPaused(TelegramGroup $group): void
    {
        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            throw CupException::paused($pause->value);
        }
    }

    /** The day of the cup `$now` falls into (1…days), or null - before the start, after the end of the last day. No query. */
    private function currentDay(Cup $cup, CarbonImmutable $now): ?int
    {
        if ($cup->starts_at === null) {
            return null;
        }
        $day = CupClock::dayNumber($cup->starts_at, $cup->timezone, $now);

        return $day >= 1 && $day <= $cup->days ? $day : null;
    }

    /** `FOR NO KEY UPDATE` of the cup on PostgreSQL (SQLite: one writer, the lock is the database). */
    private function lockCup(int $cupId): void
    {
        $query = DB::table('cups')->where('id', $cupId);
        (DB::getDriverName() === 'pgsql' ? $query->lock('for no key update') : $query->lockForUpdate())->value('id');
    }
}
