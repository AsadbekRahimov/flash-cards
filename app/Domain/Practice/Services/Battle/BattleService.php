<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Battle;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Content\Services\CourseCatalogService;
use App\Domain\Engagement\Exceptions\FriendshipException;
use App\Domain\Engagement\Services\FriendshipService;
use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Practice\DTO\AudioFailureOutcome;
use App\Domain\Practice\DTO\BattleAnswerOutcome;
use App\Domain\Practice\DTO\ElementState;
use App\Domain\Practice\DTO\LocaleSet;
use App\Domain\Practice\DTO\MaterialScope;
use App\Domain\Practice\DTO\ModeAvailability;
use App\Domain\Practice\DTO\Snapshot;
use App\Domain\Practice\Enums\BattleDmKind;
use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleRole;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Modes\ModeRegistry;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeAudioService;
use App\Domain\Practice\Services\PracticeMaterialService;
use App\Domain\Practice\Services\PracticeSnapshotBuilder;
use App\Jobs\SendBattleDmJob;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The actions of a 1-on-1 battle (docs/16 §1.3, §8): invite / accept / decline / cancel / ready / answer / surrender /
 * audioFailed. The reads are {@see BattleReader}'s, the clocks {@see BattleClock}'s.
 *
 * Everything that changes a match happens under `FOR UPDATE` of its `battles` row, in the order of docs/16 §9: the row ->
 * the two `battle_participants` of the match -> `battle_answers` -> the events after the commit. Nothing else is locked
 * (friendships, learners, courses are read before the transaction or inside it without a lock); Telegram is a job after
 * the commit. "One active battle per learner" is the unique `battle_participants.active_learner_id`: the invitation and
 * the acceptance write it and turn its violation into 409 `battle_busy` - two invitations by one learner, or one learner
 * accepting two invitations at once, are decided by the index, never by a check that could race (§8.2).
 *
 * Every action first lets the clock move the match (`advance`), so an expired invitation, a deadline that has passed or
 * an absent player are seen by whoever comes first; a refusal raised AFTER the lazy transition is returned out of the
 * transaction and thrown after the commit, so the transition is kept (the same net as PracticeSessionService).
 */
final class BattleService
{
    public function __construct(
        private readonly PracticeAccess $access,
        private readonly PracticeMaterialService $material,
        private readonly PracticeSnapshotBuilder $snapshots,
        private readonly ModeRegistry $modes,
        private readonly LocaleResolver $locales,
        private readonly FriendshipService $friendships,
        private readonly BattleFriends $friends,
        private readonly CourseCatalogService $catalog,
        private readonly PracticeAudioService $audio,
        private readonly BattleClock $clock,
        private readonly BattleSettings $settings,
        private readonly BattleReader $reader,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * Invites a friend (docs/16 §8.2). Outside the transaction: the access of both, the switches, the friendship, the course
     * (published, not private) and the lesson, the snapshot of exactly `practice.battle.rounds` rounds for the languages of both
     * players. Inside: the row and the two participants - the challenger busy with the match (a violation of the unique
     * `active_learner_id` is 409 `battle_busy` with the id of the match that keeps them busy). After the commit: the event
     * `battle.invited` and the DM to the friend.
     *
     * A rematch (`$rematchOf` - a battle of the learner that is over) needs no `$friendshipId`: the friend is the other player
     * of that battle, the mode, the course and the lesson are its own (what was sent for them is ignored); the snapshot is new.
     *
     * @throws BattleException battle_disabled | battle_busy | battle_friend_no_access | not_friends | battle_material_unavailable |
     *                         battle_not_found (a rematch of a battle that is not the learner's) | battle_wrong_state (a rematch of a match still on)
     * @throws PracticeException practice_unavailable | practice_pro_required | practice_mode_disabled | course_not_found | lesson_not_found | lesson_not_in_course
     * @throws FriendshipException friendship_not_found
     */
    public function invite(
        Learner $by,
        ?int $friendshipId,
        PracticeMode $mode,
        string $courseSlug,
        ?int $lessonId,
        ?int $rematchOf,
        ?CarbonImmutable $now = null,
    ): Battle {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);
        $this->access->assertBattleEnabled();

        $source = $rematchOf === null ? null : $this->rematchSource($by, $rematchOf);
        if ($source !== null) {
            $mode = $source->mode;
        }
        $this->access->assertModeEnabled($mode);

        [$friend, $friendship] = $source === null
            ? $this->friends->byFriendship($by, $friendshipId ?? throw FriendshipException::notFound())
            : $this->friends->ofRematch($by, $source);
        $this->access->assertFriendCanPractice($friend);

        [$course, $lesson] = $source === null
            ? $this->scopeOf($courseSlug, $lessonId)
            : $this->scopeOfRematch($source);

        $locales = new LocaleSet([$this->locales->forLearner($by), $this->locales->forLearner($friend)]);
        $snapshot = $this->snapshot($by, $mode, new MaterialScope($course, $lesson), $locales);

        $attributes = [
            'challenger_id' => $by->id,
            'opponent_id' => $friend->id,
            'friendship_id' => $friendship->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson?->id,
            'mode' => $mode,
            'status' => BattleStatus::Invited,
            'end_reason' => null,
            'snapshot' => $snapshot->toArray(),
            'current_round' => 0,
            'phase' => null,
            'invite_expires_at' => $now->addSeconds($this->settings->inviteTtlSeconds()),
            'rematch_of_id' => $source?->id,
            'state_version' => 0,
            'meta' => [],
        ];
        $challengerLocale = $this->locales->forLearner($by);
        $opponentLocale = $this->locales->forLearner($friend);

        try {
            return DB::transaction(function () use ($by, $friend, $attributes, $challengerLocale, $opponentLocale, $source): Battle {
                $battle = Battle::query()->create($attributes);

                BattleParticipant::query()->create([
                    'battle_id' => $battle->id,
                    'learner_id' => $by->id,
                    'role' => BattleRole::Challenger,
                    'active_learner_id' => $by->id,
                    'locale' => $challengerLocale,
                ]);
                BattleParticipant::query()->create([
                    'battle_id' => $battle->id,
                    'learner_id' => $friend->id,
                    'role' => BattleRole::Opponent,
                    'active_learner_id' => null,
                    'locale' => $opponentLocale,
                ]);

                DB::afterCommit(function () use ($battle, $by, $source): void {
                    $this->events->record(ProductEventName::BattleInvited, [
                        'battle_id' => $battle->id,
                        'mode' => $battle->mode->value,
                        'course_id' => $battle->course_id,
                        'lesson_id' => $battle->lesson_id,
                        'rematch' => $source !== null,
                    ], learner: $by);
                    SendBattleDmJob::send(BattleDmKind::Invited, $battle->id);
                });

                return $battle;
            });
        } catch (UniqueConstraintViolationException) {
            // The challenger is busy with another match (their active_learner_id is taken): the database decided.
            throw BattleException::busy($this->activeBattleIdOf($by));
        }
    }

    /**
     * The invited friend accepts (docs/16 §8.2): the match becomes a `room`, both players are present from now. Idempotent
     * for a match that is on already (a double tap). The acceptance takes the learner's `active_learner_id`: busy with
     * another match -> 409 `battle_busy`. A friendship that ended in the meantime closes the invitation (`canceled`).
     *
     * @throws BattleException battle_not_found | battle_not_recipient | battle_closed | battle_busy | battle_disabled
     * @throws PracticeException practice_unavailable | practice_pro_required
     */
    public function accept(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);

        try {
            return $this->transact(function () use ($by, $battleId, $now): Battle|BattleException {
                $battle = $this->lock($battleId, $by);
                if ($battle === null) {
                    return BattleException::notFound();
                }
                $this->clock->advance($battle, $now);

                if ($battle->status->isLive()) {
                    return $battle;
                }
                if ($battle->status->isTerminal()) {
                    return BattleException::closed($battle->end_reason);
                }
                if ($battle->opponent_id !== $by->id) {
                    return BattleException::notRecipient();
                }
                if (! $this->friendships->areFriends($battle->challenger_id, $battle->opponent_id)) {
                    $this->clock->close($battle, BattleEndReason::Canceled, $now);

                    return BattleException::closed(BattleEndReason::Canceled);
                }
                if (! $this->access->battleEnabled()) {
                    return BattleException::disabled();
                }

                // The unique index decides: a violation (busy with another match) leaves the transaction and is a 409 below.
                $me = $battle->participant($by->id);
                BattleParticipant::query()->whereKey($me->id)->update(['active_learner_id' => $by->id, 'last_seen_at' => $now, 'updated_at' => $now]);
                $me->active_learner_id = $by->id;
                $me->last_seen_at = $now;

                $challenger = $battle->participant($battle->challenger_id);
                BattleParticipant::query()->whereKey($challenger->id)->update(['last_seen_at' => $now, 'updated_at' => $now]);
                $challenger->last_seen_at = $now;

                $battle->forceFill([
                    'status' => BattleStatus::Room,
                    'accepted_at' => $now,
                    'room_expires_at' => $now->addSeconds($this->settings->roomTtlSeconds()),
                    'state_version' => $battle->state_version + 1,
                ])->save();

                DB::afterCommit(fn () => $this->events->record(ProductEventName::BattleAccepted, ['battle_id' => $battle->id], learner: $battle->challenger));

                return $battle;
            });
        } catch (UniqueConstraintViolationException) {
            throw BattleException::busy($this->activeBattleIdOf($by));
        }
    }

    /**
     * The invited friend declines an invitation -> closed / declined.
     *
     * @throws BattleException battle_not_found | battle_closed | battle_wrong_state | battle_not_recipient
     */
    public function decline(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);

        return $this->transact(function () use ($by, $battleId, $now): Battle|BattleException {
            $battle = $this->lock($battleId, $by);
            if ($battle === null) {
                return BattleException::notFound();
            }
            $this->clock->advance($battle, $now);

            if ($battle->status->isTerminal()) {
                return BattleException::closed($battle->end_reason);
            }
            if ($battle->status !== BattleStatus::Invited) {
                return BattleException::wrongState($battle->status);
            }
            if ($battle->opponent_id !== $by->id) {
                return BattleException::notRecipient();
            }

            $this->clock->close($battle, BattleEndReason::Declined, $now);

            return $battle;
        });
    }

    /**
     * The challenger withdraws an invitation, or either player leaves the room / the countdown -> closed / canceled
     * (`meta.left` names the role that left a room). A match that is playing is left by `surrender`.
     *
     * @throws BattleException battle_not_found | battle_closed | battle_not_challenger | battle_wrong_state
     */
    public function cancel(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);

        return $this->transact(function () use ($by, $battleId, $now): Battle|BattleException {
            $battle = $this->lock($battleId, $by);
            if ($battle === null) {
                return BattleException::notFound();
            }
            $this->clock->advance($battle, $now);

            if ($battle->status->isTerminal()) {
                return BattleException::closed($battle->end_reason);
            }
            if ($battle->status === BattleStatus::Invited) {
                if ($battle->challenger_id !== $by->id) {
                    return BattleException::notChallenger();
                }
                $this->clock->close($battle, BattleEndReason::Canceled, $now);

                return $battle;
            }
            if ($battle->status === BattleStatus::Playing) {
                return BattleException::wrongState($battle->status);
            }

            $this->clock->close($battle, BattleEndReason::Canceled, $now, [BattleClock::META_LEFT => $battle->participant($by->id)->role->value]);

            return $battle;
        });
    }

    /**
     * A player is ready (docs/16 §8.1, §10.3). A dictation first checks the five clips on the disk - a missing one voids the
     * match (`closed / voided`, `meta.voided_reason = audio_missing`). Both ready -> `countdown`: `starts_at = now + countdown_seconds`,
     * round 1 with its deadline `starts_at + round_seconds` (no phase before the match starts), the event `battle.started`.
     * Nothing changes for a repeated tap, or in a countdown / a game (both are ready there).
     *
     * @throws BattleException battle_not_found | battle_closed | battle_wrong_state (an invitation)
     */
    public function ready(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);

        return $this->transact(function () use ($by, $battleId, $now): Battle|BattleException {
            $battle = $this->lock($battleId, $by);
            if ($battle === null) {
                return BattleException::notFound();
            }
            $me = $battle->participant($by->id);
            if ($battle->status->isLive()) {
                $this->clock->touch($battle, $me, $now);
            }
            $this->clock->advance($battle, $now);

            if ($battle->status->isTerminal()) {
                return BattleException::closed($battle->end_reason);
            }
            if ($battle->status === BattleStatus::Invited) {
                return BattleException::wrongState($battle->status);
            }
            if ($battle->status !== BattleStatus::Room || $me->ready_at !== null) {
                return $battle;
            }

            $snapshot = Snapshot::fromArray($battle->snapshot);
            $missing = $battle->mode === PracticeMode::Dictation ? $this->missingClip($snapshot) : null;
            if ($missing !== null) {
                $this->clock->close($battle, BattleEndReason::Voided, $now, [BattleClock::META_VOIDED_REASON => BattleClock::VOIDED_AUDIO_MISSING]);
                $this->recordAudioFailed($battle, $by, $missing, true);

                return $battle;
            }

            BattleParticipant::query()->whereKey($me->id)->update(['ready_at' => $now, 'updated_at' => $now]);
            $me->ready_at = $now;

            $other = $battle->participant($battle->otherId($by->id));
            if ($other->ready_at === null) {
                $battle->forceFill(['state_version' => $battle->state_version + 1])->save();

                return $battle;
            }

            $startsAt = $now->addSeconds($this->settings->countdownSeconds());
            $battle->forceFill([
                'status' => BattleStatus::Countdown,
                'starts_at' => $startsAt,
                'current_round' => 1,
                'phase' => null,
                'round_deadline_at' => $startsAt->addSeconds($this->settings->roundSeconds($battle->mode)),
                'phase_ends_at' => null,
                'state_version' => $battle->state_version + 1,
            ])->save();

            DB::afterCommit(fn () => $this->events->record(ProductEventName::BattleStarted, ['battle_id' => $battle->id, 'mode' => $battle->mode->value], learner: $battle->challenger));

            return $battle;
        });
    }

    /**
     * The final answer of a player to a round (docs/16 §8.5). Under the lock: the clock first; a repeated `request_id`
     * returns the stored verdict and points; then the round must be the current one (`round_not_open` for a later one or
     * during the countdown, `round_closed` for an earlier one, for a round in its reveal or past its deadline and grace); a
     * second answer to the round is 409 `answer_already_accepted` with the first one's verdict and points; the handler of
     * the mode validates the shape (422) and grades. The second answer of a round resolves it (the reveal begins, the scores
     * grow). The review is not returned here: it comes with the resolved round.
     *
     * @param  array<string, mixed>  $answer
     *
     * @throws BattleException battle_not_found | battle_closed | battle_wrong_state | round_not_open | round_closed
     * @throws PracticeException answer_already_accepted | invalid_answer_shape | unknown_element_id | practice_unavailable | practice_pro_required
     */
    public function answer(
        Learner $by,
        int $battleId,
        string $requestId,
        int $round,
        array $answer,
        ?CarbonImmutable $now = null,
    ): BattleAnswerOutcome {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);
        $requestId = strtolower($requestId);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                return $this->transact(function () use ($by, $battleId, $requestId, $round, $answer, $now): BattleAnswerOutcome|BattleException|PracticeException {
                    $battle = $this->lock($battleId, $by);
                    if ($battle === null) {
                        return BattleException::notFound();
                    }
                    $me = $battle->participant($by->id);
                    if ($battle->status->isLive()) {
                        $this->clock->touch($battle, $me, $now);
                    }
                    $this->clock->advance($battle, $now);

                    // One read for all three questions: a repeat of this request, my answer to the round, the opponent's.
                    $rows = $battle->status === BattleStatus::Playing || $battle->status->isTerminal()
                        ? $this->answersOf($battle, $by, $round, $requestId)
                        : new Collection;
                    $repeat = $rows->first(fn (BattleAnswer $row): bool => $row->learner_id === $by->id && $row->request_id === $requestId);
                    if ($repeat !== null) {
                        $resolved = $battle->status->isTerminal() || $repeat->round < $battle->current_round || $battle->phase !== BattlePhase::Answering;

                        return new BattleAnswerOutcome($repeat->verdict, $repeat->points, $repeat->accepted_at, $resolved, $this->reader->pollView($battle, $by, $now));
                    }

                    if ($battle->status->isTerminal()) {
                        return BattleException::closed($battle->end_reason);
                    }
                    if ($battle->status === BattleStatus::Countdown) {
                        return BattleException::roundNotOpen($round, null);
                    }
                    if ($battle->status !== BattleStatus::Playing) {
                        return BattleException::wrongState($battle->status);
                    }
                    if ($round > $battle->current_round) {
                        return BattleException::roundNotOpen($round, $battle->phase);
                    }
                    if ($round < $battle->current_round || ! $this->clock->acceptsAnswers($battle, $now)) {
                        return BattleException::roundClosed($round, $battle->phase);
                    }

                    $mine = $rows->first(fn (BattleAnswer $row): bool => $row->learner_id === $by->id && $row->round === $round);
                    if ($mine !== null) {
                        return PracticeException::answerAlreadyAccepted($mine->verdict, $mine->points);
                    }

                    $element = Snapshot::fromArray($battle->snapshot)->at($round - 1)
                        ?? throw new LogicException("Battle {$battle->id} has no element for round {$round}.");
                    try {
                        $graded = $this->modes->for($battle->mode)->grade($element, $answer, ElementState::open());
                    } catch (PracticeException $refused) {
                        return $refused;
                    }

                    $row = BattleAnswer::query()->create([
                        'battle_id' => $battle->id,
                        'learner_id' => $by->id,
                        'round' => $round,
                        'request_id' => $requestId,
                        'payload' => $graded->normalizedAnswer,
                        'verdict' => $graded->verdict,
                        'points' => max(0, min($this->settings->roundPoints(), $graded->points)),
                        'accepted_at' => $now,
                    ]);

                    $meta = $battle->meta;
                    $answered = is_array($meta[BattleClock::META_ANSWERED] ?? null) ? $meta[BattleClock::META_ANSWERED] : [];
                    $answered[$me->role->value] = $round;
                    $meta[BattleClock::META_ANSWERED] = $answered;
                    $battle->meta = $meta;

                    $theirs = $rows->first(fn (BattleAnswer $other): bool => $other->learner_id !== $by->id && $other->round === $round);
                    if ($theirs !== null) {
                        $this->clock->resolveRound($battle, $now, new Collection([$row, $theirs]));
                    } else {
                        $battle->forceFill(['state_version' => $battle->state_version + 1])->save();
                    }

                    return new BattleAnswerOutcome($row->verdict, $row->points, $now, $theirs !== null, $this->reader->pollView($battle, $by, $now));
                });
            } catch (UniqueConstraintViolationException) {
                // The net of docs/16 §8.5: a row for this round or request landed between the read and the insert (it cannot
                // under the lock, yet the index is the truth) - read again and answer from what is there.
            }
        }

        throw new LogicException('Could not take the answer of the battle: the answers keep changing under us.');
    }

    /**
     * A player gives up in the countdown or the game -> finished / surrender, the opponent wins with the scores as they are.
     *
     * @throws BattleException battle_not_found | battle_closed | battle_wrong_state
     */
    public function surrender(Learner $by, int $battleId, ?CarbonImmutable $now = null): Battle
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);

        return $this->transact(function () use ($by, $battleId, $now): Battle|BattleException {
            $battle = $this->lock($battleId, $by);
            if ($battle === null) {
                return BattleException::notFound();
            }
            $me = $battle->participant($by->id);
            if ($battle->status->isLive()) {
                $this->clock->touch($battle, $me, $now);
            }
            $this->clock->advance($battle, $now);

            if ($battle->status->isTerminal()) {
                return BattleException::closed($battle->end_reason);
            }
            if ($battle->status !== BattleStatus::Countdown && $battle->status !== BattleStatus::Playing) {
                return BattleException::wrongState($battle->status);
            }

            BattleParticipant::query()->whereKey($me->id)->update(['surrendered_at' => $now, 'updated_at' => $now]);
            $me->surrendered_at = $now;
            $this->clock->finish($battle, BattleEndReason::Surrender, $battle->otherId($by->id), $now);

            return $battle;
        });
    }

    /**
     * A player reports a clip that does not play (docs/16 §10.3). The server looks at the file: missing -> the match is
     * voided (`closed / voided`, `meta.voided_reason = audio_missing`, `meta.audio_failed_by = role`) and `verified` is true;
     * there -> the trouble is local, the match goes on, `verified` is false. A repeated `request_id` answers the same.
     *
     * @throws BattleException battle_not_found | battle_closed | battle_wrong_state (an invitation)
     * @throws PracticeException unknown_element_id (not an element with audio of this match)
     */
    public function audioFailed(Learner $by, int $battleId, string $requestId, string $elementId, ?CarbonImmutable $now = null): AudioFailureOutcome
    {
        $now ??= CarbonImmutable::now();
        $this->access->assertCanPractice($by);
        $requestId = strtolower($requestId);

        return $this->transact(function () use ($by, $battleId, $requestId, $elementId, $now): AudioFailureOutcome|BattleException|PracticeException {
            $battle = $this->lock($battleId, $by);
            if ($battle === null) {
                return BattleException::notFound();
            }
            $me = $battle->participant($by->id);
            if ($battle->status->isLive()) {
                $this->clock->touch($battle, $me, $now);
            }
            $this->clock->advance($battle, $now);

            $meta = $battle->meta;
            $reports = is_array($meta['audio_reports'] ?? null) ? $meta['audio_reports'] : [];
            if (array_key_exists($requestId, $reports)) {
                return new AudioFailureOutcome((bool) $reports[$requestId], $battle);
            }

            if ($battle->status->isTerminal()) {
                return BattleException::closed($battle->end_reason);
            }
            if ($battle->status === BattleStatus::Invited) {
                return BattleException::wrongState($battle->status);
            }

            $snapshot = Snapshot::fromArray($battle->snapshot);
            $clip = $this->audio->clipFor($snapshot, $elementId);
            if ($clip === null) {
                return PracticeException::unknownElementId();
            }

            $verified = ! $this->audio->hasFile($clip->disk, $clip->path);
            $reports[$requestId] = $verified;
            $meta['audio_reports'] = array_slice($reports, -20, null, true);
            $battle->meta = $meta;

            if ($verified) {
                $this->clock->close($battle, BattleEndReason::Voided, $now, [
                    BattleClock::META_VOIDED_REASON => BattleClock::VOIDED_AUDIO_MISSING,
                    'audio_failed_by' => $me->role->value,
                ]);
            } else {
                $battle->save(); // the report only: nothing the players can see, the version stays
            }
            $this->recordAudioFailed($battle, $by, $snapshot->element($elementId)?->get('source'), $verified);

            return new AudioFailureOutcome($verified, $battle);
        });
    }

    // ------------------------------------------------------------------ the pieces

    /**
     * The row under `FOR UPDATE` with its participants loaded fresh; null when there is no such battle of the learner (a
     * stranger's looks the same).
     */
    private function lock(int $battleId, Learner $by): ?Battle
    {
        /** @var Battle|null $battle */
        $battle = Battle::query()->whereKey($battleId)->lockForUpdate()->first();
        if ($battle === null || ! $battle->involves($by->id)) {
            return null;
        }
        $battle->load('participants');

        return $battle;
    }

    /**
     * Runs the work in a transaction; a refusal RETURNED by it is thrown after the commit, so a lazy transition the work made
     * (an expired invitation closed, a round resolved by its deadline) is kept.
     *
     * @template T
     *
     * @param  Closure(): (T|BattleException|PracticeException)  $work
     * @return T
     */
    private function transact(Closure $work): mixed
    {
        $outcome = DB::transaction($work);
        if ($outcome instanceof BattleException || $outcome instanceof PracticeException) {
            throw $outcome;
        }

        return $outcome;
    }

    /**
     * The answers that decide an answer of `$by` to `$round` in one read: every answer of the round (mine - a second answer;
     * the opponent's - the round is resolved with mine) and my row with this `request_id` (a repeat, whatever its round).
     *
     * @return Collection<int, BattleAnswer>
     */
    private function answersOf(Battle $battle, Learner $by, int $round, string $requestId): Collection
    {
        /** @var Collection<int, BattleAnswer> $rows */
        $rows = BattleAnswer::query()
            ->where('battle_id', $battle->id)
            ->where(function (Builder $query) use ($by, $round, $requestId): void {
                $query->where('round', $round)
                    ->orWhere(fn (Builder $mine) => $mine->where('learner_id', $by->id)->where('request_id', $requestId));
            })
            ->get();

        return $rows;
    }

    /**
     * The battle of the learner that is over, whose rematch is asked for.
     *
     * @throws BattleException battle_not_found | battle_wrong_state
     */
    private function rematchSource(Learner $by, int $battleId): Battle
    {
        /** @var Battle|null $source */
        $source = Battle::query()->whereKey($battleId)->first();
        if ($source === null || ! $source->involves($by->id)) {
            throw BattleException::notFound();
        }
        if (! $source->status->isTerminal()) {
            throw BattleException::wrongState($source->status);
        }

        return $source;
    }

    /** @return array{0: Course, 1: Lesson|null} */
    private function scopeOf(string $courseSlug, ?int $lessonId): array
    {
        $course = $this->material->courseForBattle($courseSlug);

        return [$course, $lessonId === null ? null : $this->material->lesson($course, $lessonId)];
    }

    /**
     * The course and the lesson of the battle a rematch repeats; they must still be playable.
     *
     * @return array{0: Course, 1: Lesson|null}
     */
    private function scopeOfRematch(Battle $source): array
    {
        $course = Course::query()->find($source->course_id);
        if ($course === null || ! $this->catalog->isAvailable($course)) {
            throw PracticeException::courseNotFound();
        }

        return [$course, $source->lesson_id === null ? null : $this->material->lesson($course, $source->lesson_id)];
    }

    /**
     * The snapshot of exactly `rounds` rounds for both languages. Too little material is 422 `battle_material_unavailable` with
     * the numbers (and the event `practice.not_enough_material` with `battle = true`).
     */
    private function snapshot(Learner $by, PracticeMode $mode, MaterialScope $scope, LocaleSet $locales): Snapshot
    {
        $handler = $this->modes->for($mode);

        try {
            $availability = $handler->availability($scope, null, $locales);
            if (! $availability->isAvailable) {
                throw PracticeException::notEnoughMaterial($mode, $availability, $scope->course->slug, $scope->lesson?->id);
            }

            return $this->snapshots->build($mode, $scope, null, $locales, $this->settings->rounds(), strictSize: true);
        } catch (PracticeException $e) {
            if ($e->errorCode !== PracticeException::NOT_ENOUGH_MATERIAL) {
                throw $e;
            }

            $this->events->record(ProductEventName::PracticeNotEnoughMaterial, [
                'mode' => $mode->value,
                'course_id' => $scope->course->id,
                'lesson_id' => $scope->lesson?->id,
                'available' => (int) ($e->details['available'] ?? 0),
                'required' => (int) ($e->details['required'] ?? 0),
                'reason' => $e->details['reason'] ?? null,
                'battle' => true,
            ], learner: $by);

            $reason = $e->details['reason'] ?? null;

            throw BattleException::materialUnavailable(
                $mode,
                ModeAvailability::notEnough((int) ($e->details['available'] ?? 0), (int) ($e->details['required'] ?? 0), is_string($reason) ? $reason : ModeAvailability::REASON_NO_ITEMS),
                $scope->course->slug,
                $scope->lesson?->id,
            );
        }
    }

    /** The source (`phrase` | `word`) of the first element of a dictation whose clip is not on the disk; null when all five play. */
    private function missingClip(Snapshot $snapshot): ?string
    {
        foreach ($snapshot->elements as $element) {
            $clip = $this->audio->clipFor($snapshot, $element->id);
            if ($clip !== null && ! $this->audio->hasFile($clip->disk, $clip->path)) {
                $source = $element->get('source');

                return is_string($source) ? $source : 'phrase';
            }
        }

        return null;
    }

    private function recordAudioFailed(Battle $battle, Learner $by, mixed $source, bool $verified): void
    {
        $props = [
            'battle_id' => $battle->id,
            'source' => is_string($source) ? $source : null,
            'reason' => $verified ? 'file_missing' : 'client_report',
            'verified' => $verified,
        ];
        DB::afterCommit(fn () => $this->events->record(ProductEventName::PracticeAudioFailed, $props, learner: $by));
    }

    /** The match the learner is busy with, if any (the `details.battle_id` of 409 `battle_busy`). */
    private function activeBattleIdOf(Learner $learner): ?int
    {
        $id = BattleParticipant::query()->where('active_learner_id', $learner->id)->value('battle_id');

        return $id === null ? null : (int) $id;
    }
}
