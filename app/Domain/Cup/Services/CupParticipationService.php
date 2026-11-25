<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ProductEventRecorder;
use App\Domain\Cup\DTO\CupJoinOutcome;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Cup\Support\CupClock;
use App\Domain\Cup\Support\DisplayName;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Joining a cup, the name in it, leaving the chat (docs/33 §2.3, §4.5, §5.4).
 *
 * Joining asks Telegram afresh (a forwarded invitation gives nothing to a stranger), then takes `FOR SHARE` of the cup: the start and
 * the finish hold `FOR UPDATE` of it, so a join either lands before the start (counted among the two players, plays from day 1) or
 * after it (plays from the current day, `first_day`), and never after the finish. A learner who left the chat and came back gets
 * his old row back - the same scores; the days he missed stay empty.
 */
final class CupParticipationService
{
    public const MAX_RENAMES = 3;

    public function __construct(
        private readonly CupMembershipGate $gate,
        private readonly CupStandings $standings,
        private readonly ProductEventRecorder $events,
    ) {}

    /**
     * @throws CupException cup_unavailable | cup_not_open | cup_closed | cup_display_name_invalid | cup_not_member | cup_paused
     *                      | cup_unverifiable | cup_check_busy
     */
    public function join(Cup $cup, Learner $learner, ?string $displayName = null, ?CarbonImmutable $now = null): CupJoinOutcome
    {
        $now ??= CarbonImmutable::now();
        $name = $displayName === null ? DisplayName::fromFirstName($learner->first_name, $learner->id) : DisplayName::validate($displayName);
        $this->assertJoinable($cup, $now);
        if (! CupAccess::allowsGroup($cup->telegramGroup)) {
            throw CupException::unavailable();
        }

        $askedAt = $this->gate->assertCanJoin($cup, $learner, $now);

        /** @var array{0: CupParticipant, 1: bool, 2: bool} $outcome */
        $outcome = DB::transaction(function () use ($cup, $learner, $name, $now, $askedAt): array {
            /** @var Cup $locked */
            $locked = Cup::query()->whereKey($cup->id)->sharedLock()->firstOrFail();
            $this->assertJoinable($locked, $now);
            $firstDay = $locked->status === CupStatus::Running && $locked->starts_at !== null
                ? max(1, CupClock::dayNumber($locked->starts_at, $locked->timezone, $now))
                : 1;

            $created = DB::table('cup_participants')->insertOrIgnore([
                'cup_id' => $locked->id,
                'learner_id' => $learner->id,
                'display_name' => $name,
                'status' => CupParticipantStatus::Active->value,
                'joined_at' => $now,
                'first_day' => $firstDay,
                'membership_checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]) > 0;
            /** @var CupParticipant $participant */
            $participant = CupParticipant::query()->where('cup_id', $locked->id)->where('learner_id', $learner->id)->lockForUpdate()->firstOrFail();
            $returned = false;
            if (! $created) {
                if ($participant->left_at !== null && $participant->left_at->getTimestamp() > $askedAt) {
                    // Telegram said "member" before the leave we recorded (the leave is the newer fact, CupMembershipGate).
                    throw CupException::notMember();
                }
                $returned = $participant->status === CupParticipantStatus::Left;
                $participant->forceFill(['status' => CupParticipantStatus::Active, 'left_at' => null, 'membership_checked_at' => $now, 'updated_at' => $now])->save();
            }

            return [$participant, $created, $returned];
        });

        [$participant, $created, $returned] = $outcome;
        if ($created || $returned) {
            $this->standings->forget($cup->id);
        }
        if ($created) {
            $this->events->record(ProductEventName::CupJoined, ['cup_id' => $cup->id, 'participant_id' => $participant->id, 'first_day' => $participant->first_day], learner: $learner);
        }

        return new CupJoinOutcome($participant, $created);
    }

    /**
     * The name in the tournament (`PATCH /cups/{id}/me`): no more than {@see MAX_RENAMES} times per cup, only in an open cup, only by
     * an `active` participant who is a member of the chat RIGHT NOW (docs/33 §7.2, §11.2 п. 12): the bot publishes the name in the
     * chat, so one who left or was removed - or is no longer in the chat - cannot put a new one there. A `left` participant gets the
     * 403 of a stranger without a call; a fresh check that says "not a member" makes him `left` (as the start of a round does);
     * Telegram out of reach, the bot unable to check or a running check answer with their reasons (he is a participant). The
     * checks run before the transaction (no lock across the network) and the status is read again under the lock of the row.
     *
     * @throws CupException cup_display_name_invalid | cup_rename_limit | cup_closed | cup_not_member | cup_paused | cup_unverifiable
     *                      | cup_check_busy
     */
    public function rename(Cup $cup, CupParticipant $participant, Learner $learner, string $displayName, ?CarbonImmutable $now = null): CupParticipant
    {
        $now ??= CarbonImmutable::now();
        if ($participant->cup_id !== $cup->id || $participant->learner_id !== $learner->id || $participant->status !== CupParticipantStatus::Active) {
            throw CupException::notMember();
        }
        $name = DisplayName::validate($displayName);
        if ($cup->status !== CupStatus::Scheduled && $cup->status !== CupStatus::Running) {
            throw CupException::closed();
        }
        if ($participant->display_name === $name) {
            return $participant;
        }
        if ($participant->rename_count >= self::MAX_RENAMES) {
            throw CupException::renameLimit();
        }

        $this->gate->admitToRename($cup, $participant, $learner, $now);

        $renamed = DB::transaction(function () use ($participant, $name, $now): CupParticipant {
            /** @var CupParticipant $locked */
            $locked = CupParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CupParticipantStatus::Active) {
                throw CupException::notMember();
            }
            $status = DB::table('cups')->where('id', $locked->cup_id)->value('status');
            if (! in_array($status, [CupStatus::Scheduled->value, CupStatus::Running->value], true)) {
                throw CupException::closed();
            }
            if ($locked->display_name === $name) {
                return $locked;
            }
            if ($locked->rename_count >= self::MAX_RENAMES) {
                throw CupException::renameLimit();
            }
            $locked->forceFill(['display_name' => $name, 'rename_count' => $locked->rename_count + 1, 'updated_at' => $now])->save();

            return $locked;
        });
        $this->standings->forget($renamed->cup_id);

        return $renamed;
    }

    /** An administrator of the platform resets an offensive name (docs/33 §8, K9): back to the Telegram first name; no rename is spent. */
    public function resetDisplayName(CupParticipant $participant, ?CarbonImmutable $now = null): CupParticipant
    {
        $now ??= CarbonImmutable::now();
        $firstName = DB::table('learners')->where('id', $participant->learner_id)->value('first_name');
        $participant->forceFill(['display_name' => DisplayName::fromFirstName(is_string($firstName) ? $firstName : null, $participant->learner_id), 'updated_at' => $now])->save();
        $this->standings->forget($participant->cup_id);

        return $participant;
    }

    /**
     * Telegram told us the learner left the chat (`chat_member` / `left_chat_member`, hooked by K6 into `ChatMemberHandler`): his
     * participation in the open cup of the group becomes `left` - no new attempt; a started one plays out to its own deadline. Coming
     * back is NOT taken from Telegram's event: the next fresh check (join, start of a round) makes him `active` again.
     *
     * `$at` is the time of the event (Telegram's `date`): a late or repeated "left" older than his last successful check
     * (`membership_checked_at` - he came back since) changes nothing. The reused answer of the throttle is dropped and the time of the
     * event marked ({@see CupMembershipGate::markLeftAt()}), so a check asked before the leave cannot write "member" back for its 10 s.
     */
    public function onLeftChat(Learner $learner, TelegramGroup $group, ?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $cupIds = DB::table('cups')->where('active_group_id', $group->id)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($cupIds === []) {
            return 0;
        }

        $changed = DB::table('cup_participants')
            ->whereIn('cup_id', $cupIds)
            ->where('learner_id', $learner->id)
            ->where('status', CupParticipantStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('membership_checked_at')->orWhere('membership_checked_at', '<=', $at))
            ->update(['status' => CupParticipantStatus::Left->value, 'left_at' => $at, 'updated_at' => CarbonImmutable::now()]);

        foreach ($cupIds as $cupId) {
            CupMembershipGate::markLeftAt($cupId, $learner->id, $at);
            if ($changed > 0) {
                $this->standings->forgetAfterCommit($cupId);
            }
        }

        return $changed;
    }

    /** @throws CupException cup_not_open | cup_closed */
    private function assertJoinable(Cup $cup, CarbonImmutable $now): void
    {
        if ($cup->status === CupStatus::Draft) {
            throw CupException::notOpen();
        }
        if ($cup->status === CupStatus::Finished || $cup->status === CupStatus::Canceled) {
            throw CupException::closed();
        }
        if ($cup->status === CupStatus::Running && $cup->ends_at !== null && $now->gte($cup->ends_at)) {
            throw CupException::closed();
        }
    }
}
