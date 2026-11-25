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
use Illuminate\Support\Facades\Cache;
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
        $name = $displayName === null ? DisplayName::fromFirstName($learner->first_name, $learner->id) : DisplayName::normalize($displayName);
        if ($name === null) {
            throw CupException::displayNameInvalid();
        }
        $this->assertJoinable($cup, $now);
        if (! CupAccess::allowsGroup($cup->telegramGroup)) {
            throw CupException::unavailable();
        }

        $this->gate->assertCanJoin($cup, $learner);

        /** @var array{0: CupParticipant, 1: bool, 2: bool} $outcome */
        $outcome = DB::transaction(function () use ($cup, $learner, $name, $now): array {
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
     * The name in the tournament (`PATCH /cups/{id}/me`): no more than {@see MAX_RENAMES} times per cup.
     *
     * @throws CupException cup_display_name_invalid | cup_rename_limit | cup_closed
     */
    public function rename(CupParticipant $participant, string $displayName, ?CarbonImmutable $now = null): CupParticipant
    {
        $now ??= CarbonImmutable::now();
        $name = DisplayName::normalize($displayName) ?? throw CupException::displayNameInvalid();

        $renamed = DB::transaction(function () use ($participant, $name, $now): CupParticipant {
            /** @var CupParticipant $locked */
            $locked = CupParticipant::query()->whereKey($participant->id)->lockForUpdate()->firstOrFail();
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

    /**
     * Telegram told us the learner left the chat (`chat_member` / `left_chat_member`, hooked by K6 into `ChatMemberHandler`): his
     * participation in the open cup of the group becomes `left` - no new attempt; a started one plays out to its own deadline. Coming
     * back is NOT taken from Telegram's event: the next fresh check (join, start of a round) makes him `active` again. The reused
     * answer of the throttle is dropped, so that check really asks.
     */
    public function onLeftChat(Learner $learner, TelegramGroup $group, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $cupIds = DB::table('cups')->where('active_group_id', $group->id)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($cupIds === []) {
            return 0;
        }

        $changed = DB::table('cup_participants')
            ->whereIn('cup_id', $cupIds)
            ->where('learner_id', $learner->id)
            ->where('status', CupParticipantStatus::Active->value)
            ->update(['status' => CupParticipantStatus::Left->value, 'left_at' => $now, 'updated_at' => $now]);

        foreach ($cupIds as $cupId) {
            Cache::forget(CupMembershipGate::key($cupId, $learner->id));
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
