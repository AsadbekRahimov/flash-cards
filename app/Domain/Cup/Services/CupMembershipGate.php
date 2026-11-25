<?php

declare(strict_types=1);

namespace App\Domain\Cup\Services;

use App\Domain\Cup\Enums\CupMembership;
use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Domain\Cup\Enums\CupPauseReason;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Support\CupAccess;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and play a cup (docs/33 §4.1-§4.5): a CURRENT member of its Telegram chat, by a fresh `getChatMember` asked by the
 * server for the learner of the JWT. Nothing the client sends (the code of the cup, `start_param`, `chat_instance`) is a pass: a
 * forwarded invitation opened by a stranger asks Telegram about the stranger, and the answer is "no".
 *
 * The answer of Telegram is reused for `cup.membership.fresh_seconds` (10 s) by the same pair (cup, learner) - the throttle of
 * docs/33 §4.2, in the NON-evictable store (it guards a decision): `cup:member:v1:{cup}:{learner}`, the value a {@see CupMembership}
 * string. Only definite answers are kept. A second request of the pair waits for the first one's call under the lock
 * `cup:member-check:{cup}:{learner}` (up to `wait_seconds`), then reads its answer; one that waits too long gets 429 `cup_check_busy`.
 * A "no" to a VIEW is remembered `negative_seconds` (30 s, `cup:member-neg:v1:…`), so a stranger who keeps opening the link costs
 * Telegram one call per half a minute.
 *
 * The only decision taken WITHOUT Telegram: Telegram cannot be reached at the start of a round, and the participant is `active` with
 * a successful check (`membership_checked_at`) not older than `stale_ok_hours` (24 h) - the database, never the cache (docs/19 §3 rule 9).
 *
 * A group whose bot is out (`bot_status` left / kicked) is not asked at all: the cup is paused.
 */
final class CupMembershipGate
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly CupStandings $standings,
    ) {}

    /**
     * Viewing the cup (the invitation by its code, the table): a participant - whatever his status - sees it without a call; anybody
     * else must be a member right now. `$opaque` (a lookup by the numeric id) turns EVERY refusal into `cup_not_member`, the answer of
     * a cup that does not exist, so an id cannot be probed.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function assertCanView(Cup $cup, Learner $learner, ?CupParticipant $participant, bool $opaque): void
    {
        if ($participant !== null && $participant->cup_id === $cup->id && $participant->learner_id === $learner->id) {
            return;
        }

        $negative = self::negativeKey($cup->id, $learner->id);
        if (Cache::get($negative) !== null) {
            throw CupException::notMember();
        }

        $pause = CupAccess::pauseReason($cup->telegramGroup);
        $answer = $pause === CupPauseReason::BotRemoved ? CupMembership::BotRemoved : $this->check($cup, $learner);
        if ($answer === CupMembership::Member) {
            return;
        }
        if ($answer === CupMembership::NotMember) {
            Cache::put($negative, 1, max(1, (int) config('cup.membership.negative_seconds', 30)));
        }

        throw $opaque ? CupException::notMember() : $this->refusal($answer);
    }

    /**
     * Joining (docs/33 §4.2): a fresh check INSIDE the request of joining - not the view of a minute ago.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function assertCanJoin(Cup $cup, Learner $learner): void
    {
        $this->assertNotPaused($cup->telegramGroup);
        $answer = $this->check($cup, $learner);
        if ($answer !== CupMembership::Member) {
            throw $this->refusal($answer);
        }
    }

    /**
     * The start of a round (docs/33 §4.2, §4.4, §4.5): a member is (again) `active` and his check is written; a non-member becomes
     * `left` and is refused; Telegram out of reach lets an `active` participant checked within 24 h play.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function admitToRound(Cup $cup, CupParticipant $participant, Learner $learner, CarbonImmutable $now): void
    {
        $this->assertNotPaused($cup->telegramGroup);
        $answer = $this->check($cup, $learner);

        if ($answer === CupMembership::Member) {
            $this->markMember($participant, $now);

            return;
        }
        if ($answer === CupMembership::NotMember) {
            $this->markLeft($participant, $now);

            throw CupException::notMember();
        }
        if ($answer === CupMembership::Unavailable
            && $participant->status === CupParticipantStatus::Active
            && $participant->membership_checked_at !== null
            && $participant->membership_checked_at->gte($now->subHours(max(0, (int) config('cup.membership.stale_ok_hours', 24))))) {
            return;
        }

        throw $this->refusal($answer);
    }

    /**
     * The fresh answer of Telegram for the pair, throttled (see the class).
     *
     * @throws CupException cup_check_busy
     */
    public function check(Cup $cup, Learner $learner): CupMembership
    {
        $key = self::key($cup->id, $learner->id);
        $hit = self::read($key);
        if ($hit !== null) {
            return $hit;
        }

        $group = $cup->telegramGroup;
        try {
            return Cache::lock("cup:member-check:{$cup->id}:{$learner->id}", 10)
                ->block(max(1, (int) config('cup.membership.wait_seconds', 5)), function () use ($key, $group, $learner): CupMembership {
                    $hit = self::read($key);
                    if ($hit !== null) {
                        return $hit;
                    }

                    $answer = $this->ask($group, (int) $learner->telegram_user_id);
                    if ($answer->isDefinite()) {
                        Cache::put($key, $answer->value, max(1, (int) config('cup.membership.fresh_seconds', 10)));
                    }

                    return $answer;
                });
        } catch (LockTimeoutException) {
            throw CupException::checkBusy();
        }
    }

    /** Telegram's "this user is not in the chat" (400 "user not found" and its variants). */
    public static function saysNotMember(TelegramApiException $e): bool
    {
        $message = strtolower($e->getMessage());

        return $e->getCode() === 400
            && (str_contains($message, 'user not found') || str_contains($message, 'participant_id_invalid') || str_contains($message, 'member not found'));
    }

    public static function key(int $cupId, int $learnerId): string
    {
        return "cup:member:v1:{$cupId}:{$learnerId}";
    }

    public static function negativeKey(int $cupId, int $learnerId): string
    {
        return "cup:member-neg:v1:{$cupId}:{$learnerId}";
    }

    private function ask(TelegramGroup $group, int $telegramUserId): CupMembership
    {
        try {
            $info = $this->telegram->getChatMember($group->chat_id, $telegramUserId);
        } catch (TelegramRateLimitedException) {
            return CupMembership::Unavailable;
        } catch (TelegramApiException $e) {
            if ($e->getCode() === 403) {
                return CupMembership::BotRemoved;
            }
            if (self::saysNotMember($e)) {
                return CupMembership::NotMember;
            }
            // A refusal we cannot read: "the bot cannot see the members" when we know it is no administrator, else an outage.
            if ($e->isApiResponse() && $this->botAccess->botIsAdmin($group) === false) {
                return CupMembership::BotNotAdmin;
            }

            return CupMembership::Unavailable;
        }

        return $info->isMember ? CupMembership::Member : CupMembership::NotMember;
    }

    /** @throws CupException cup_paused */
    private function assertNotPaused(TelegramGroup $group): void
    {
        $pause = CupAccess::pauseReason($group);
        if ($pause !== null) {
            throw CupException::paused($pause->value);
        }
    }

    private function refusal(CupMembership $answer): CupException
    {
        return match ($answer) {
            CupMembership::BotRemoved => CupException::paused(CupPauseReason::BotRemoved->value),
            CupMembership::BotNotAdmin => CupException::botNotAdminUnverifiable(),
            CupMembership::Unavailable => CupException::telegramUnavailable(),
            default => CupException::notMember(),
        };
    }

    private function markMember(CupParticipant $participant, CarbonImmutable $now): void
    {
        $returned = $participant->status === CupParticipantStatus::Left;
        DB::table('cup_participants')->where('id', $participant->id)->update([
            'status' => CupParticipantStatus::Active->value,
            'left_at' => null,
            'membership_checked_at' => $now,
            'updated_at' => $now,
        ]);
        $participant->forceFill(['status' => CupParticipantStatus::Active, 'left_at' => null, 'membership_checked_at' => $now])->syncOriginal();
        if ($returned) {
            $this->standings->forgetAfterCommit($participant->cup_id);
        }
    }

    private function markLeft(CupParticipant $participant, CarbonImmutable $now): void
    {
        $changed = DB::table('cup_participants')
            ->where('id', $participant->id)
            ->where('status', CupParticipantStatus::Active->value)
            ->update(['status' => CupParticipantStatus::Left->value, 'left_at' => $now, 'updated_at' => $now]);
        $participant->forceFill(['status' => CupParticipantStatus::Left, 'left_at' => $participant->left_at ?? $now])->syncOriginal();
        if ($changed > 0) {
            $this->standings->forgetAfterCommit($participant->cup_id);
        }
    }

    private static function read(string $key): ?CupMembership
    {
        $value = Cache::get($key);

        return is_string($value) ? CupMembership::tryFrom($value) : null;
    }
}
