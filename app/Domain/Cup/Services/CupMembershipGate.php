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
 * docs/33 §4.2, in the NON-evictable store (it guards a decision): `cup:member:v1:{cup}:{learner}`, the value
 * `{@see CupMembership}@{unix time of the question}`. Only definite answers are kept. A second request of the pair waits for the first
 * one's call under the lock `cup:member-check:{cup}:{learner}` (up to `wait_seconds`), then reads its answer; one that waits too long
 * gets 429 `cup_check_busy`. A "no" to a VIEW is remembered `negative_seconds` (30 s, `cup:member-neg:v1:…`), so a stranger who keeps
 * opening the link costs Telegram one call per half a minute.
 *
 * Telegram reports that the learner LEFT ({@see CupParticipationService::onLeftChat()}): the time of the event is kept for
 * {@see LEFT_MARK_SECONDS} (`cup:member-left:v1:…`) and a reused "member" asked at or before it is not reused - a check that was asked
 * before the leave and wrote its answer after the drop of the key cannot let him in for its 10 s. The same time guards the database:
 * a "member" asked before the recorded `left_at` does not make the participant `active` again ({@see markMember()}).
 *
 * The only decision taken WITHOUT Telegram: Telegram cannot be reached at the start of a round, and the participant is `active` with
 * a successful check (`membership_checked_at`) not older than `stale_ok_hours` (24 h) - the database, never the cache (docs/19 §3 rule 9).
 *
 * A group whose bot is out (`bot_status` left / kicked) is not asked at all: the cup is paused.
 */
final class CupMembershipGate
{
    /** How long the time of a "left" event invalidates the reused answers asked before it (≥ lock + call + fresh_seconds). */
    public const LEFT_MARK_SECONDS = 120;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly GroupBotAccessChecker $botAccess,
        private readonly CupStandings $standings,
    ) {}

    /**
     * Viewing the cup (the invitation by its code, the table, docs/33 §11.2 п. 7):
     *
     *  - an `active` participant sees it without a call;
     *  - anybody else - a participant who left or was removed (`left`, in an open or a closed cup alike), a member who is not a
     *    participant - must be a member of the chat right now (a fresh check; a "no" is remembered 30 s);
     *  - why a check failed (409 `cup_paused` / `cup_unverifiable`, 503, 429 `cup_check_busy`) is told only to who already knows the
     *    cup without Telegram: a participant (by the database), or a lookup by the code of the link (`$opaque = false`). A stranger
     *    by the numeric id (`$opaque`) gets 403 `cup_not_member` for EVERY refusal - the answer of a cup that does not exist - so an id
     *    cannot be probed: only a definite "member" opens it.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function assertCanView(Cup $cup, Learner $learner, ?CupParticipant $participant, bool $opaque): void
    {
        $mine = $participant !== null && $participant->cup_id === $cup->id && $participant->learner_id === $learner->id;
        if ($mine && $participant->status === CupParticipantStatus::Active) {
            return;
        }
        $opaque = $opaque && ! $mine;

        $negative = self::negativeKey($cup->id, $learner->id);
        if (Cache::get($negative) !== null) {
            throw CupException::notMember();
        }

        try {
            $answer = CupAccess::pauseReason($cup->telegramGroup) === CupPauseReason::BotRemoved
                ? CupMembership::BotRemoved
                : $this->check($cup, $learner);
        } catch (CupException $busy) {
            throw $opaque ? CupException::notMember() : $busy;
        }
        if ($answer === CupMembership::Member) {
            return;
        }
        if ($answer === CupMembership::NotMember) {
            Cache::put($negative, 1, max(1, (int) config('cup.membership.negative_seconds', 30)));
        }

        throw $opaque ? CupException::notMember() : $this->refusal($answer);
    }

    /**
     * Joining (docs/33 §4.2): a fresh check INSIDE the request of joining - not the view of a minute ago. Returns the unix time the
     * "member" was asked at (the join does not bring back a participant whose leave is newer).
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function assertCanJoin(Cup $cup, Learner $learner, ?CarbonImmutable $now = null): int
    {
        $this->assertNotPaused($cup->telegramGroup);
        [$answer, $askedAt] = $this->checkAt($cup, $learner, $now ?? CarbonImmutable::now());
        if ($answer !== CupMembership::Member) {
            throw $this->refusal($answer);
        }

        return $askedAt;
    }

    /**
     * The start of a round (docs/33 §4.2, §4.4, §4.5): a member is (again) `active` and his check is written; a non-member becomes
     * `left` and is refused; Telegram out of reach lets an `active` participant checked within 24 h play.
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function admitToRound(Cup $cup, CupParticipant $participant, Learner $learner, CarbonImmutable $now): void
    {
        $this->admit($cup, $participant, $learner, $now, staleOk: true);
    }

    /**
     * A new name (docs/33 §7.2): it is published in the chat, so only a member right now - the start of a round without its 24 h
     * of grace (a name can wait for Telegram, a round of the day cannot).
     *
     * @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy
     */
    public function admitToRename(Cup $cup, CupParticipant $participant, Learner $learner, CarbonImmutable $now): void
    {
        $this->admit($cup, $participant, $learner, $now, staleOk: false);
    }

    /**
     * The fresh answer of Telegram for the pair, throttled (see the class).
     *
     * @throws CupException cup_check_busy
     */
    public function check(Cup $cup, Learner $learner, ?CarbonImmutable $now = null): CupMembership
    {
        return $this->checkAt($cup, $learner, $now ?? CarbonImmutable::now())[0];
    }

    /**
     * The time of a "left" event of Telegram (see the class): reused "member" answers asked at or before it are void. The caller
     * drops the reused answer too.
     */
    public static function markLeftAt(int $cupId, int $learnerId, CarbonImmutable $at): void
    {
        $key = self::leftKey($cupId, $learnerId);
        $current = Cache::get($key);
        $value = max($at->getTimestamp(), is_numeric($current) ? (int) $current : 0);
        Cache::put($key, $value, self::LEFT_MARK_SECONDS);
        Cache::forget(self::key($cupId, $learnerId));
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

    public static function leftKey(int $cupId, int $learnerId): string
    {
        return "cup:member-left:v1:{$cupId}:{$learnerId}";
    }

    /**
     * The fresh answer and the unix time it was asked at (from the reused answer, or `$now` for a new question).
     *
     * @return array{0: CupMembership, 1: int}
     *
     * @throws CupException cup_check_busy
     */
    private function checkAt(Cup $cup, Learner $learner, CarbonImmutable $now): array
    {
        $key = self::key($cup->id, $learner->id);
        $left = self::leftKey($cup->id, $learner->id);
        $hit = self::read($key, $left);
        if ($hit !== null) {
            return $hit;
        }

        $group = $cup->telegramGroup;
        try {
            return Cache::lock("cup:member-check:{$cup->id}:{$learner->id}", 10)
                ->block(max(1, (int) config('cup.membership.wait_seconds', 5)), function () use ($key, $left, $group, $learner, $now): array {
                    $hit = self::read($key, $left);
                    if ($hit !== null) {
                        return $hit;
                    }

                    $askedAt = $now->getTimestamp();
                    $answer = $this->ask($group, (int) $learner->telegram_user_id);
                    if ($answer->isDefinite()) {
                        Cache::put($key, $answer->value.'@'.$askedAt, max(1, (int) config('cup.membership.fresh_seconds', 10)));
                    }

                    return [$answer, $askedAt];
                });
        } catch (LockTimeoutException) {
            throw CupException::checkBusy();
        }
    }

    /** @throws CupException cup_not_member | cup_paused | cup_unverifiable | cup_check_busy */
    private function admit(Cup $cup, CupParticipant $participant, Learner $learner, CarbonImmutable $now, bool $staleOk): void
    {
        $this->assertNotPaused($cup->telegramGroup);
        [$answer, $askedAt] = $this->checkAt($cup, $learner, $now);

        if ($answer === CupMembership::Member) {
            if (! $this->markMember($participant, $now, $askedAt)) {
                // Telegram said "member" before the leave we recorded: the leave is the newer fact.
                throw CupException::notMember();
            }

            return;
        }
        if ($answer === CupMembership::NotMember) {
            $this->markLeft($participant, $now);

            throw CupException::notMember();
        }
        if ($staleOk
            && $answer === CupMembership::Unavailable
            && $participant->status === CupParticipantStatus::Active
            && $participant->membership_checked_at !== null
            && $participant->membership_checked_at->gte($now->subHours(max(0, (int) config('cup.membership.stale_ok_hours', 24))))) {
            return;
        }

        throw $this->refusal($answer);
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

    /**
     * A "member" asked at `$askedAt`: the participant is `active` with the check written - unless his recorded leave (`left_at`) is
     * newer than the question (a check asked before the leave, finished after it). False then, nothing written.
     */
    private function markMember(CupParticipant $participant, CarbonImmutable $now, int $askedAt): bool
    {
        $returned = $participant->status === CupParticipantStatus::Left;
        $changed = DB::table('cup_participants')
            ->where('id', $participant->id)
            ->where(fn ($q) => $q->whereNull('left_at')->orWhere('left_at', '<=', CarbonImmutable::createFromTimestamp($askedAt)))
            ->update([
                'status' => CupParticipantStatus::Active->value,
                'left_at' => null,
                'membership_checked_at' => $now,
                'updated_at' => $now,
            ]);
        if ($changed === 0) {
            return false;
        }
        $participant->forceFill(['status' => CupParticipantStatus::Active, 'left_at' => null, 'membership_checked_at' => $now])->syncOriginal();
        if ($returned) {
            $this->standings->forgetAfterCommit($participant->cup_id);
        }

        return true;
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

    /**
     * A reused answer and the time it was asked at; a "member" asked at or before the last "left" event is no answer.
     *
     * @return array{0: CupMembership, 1: int}|null
     */
    private static function read(string $key, string $leftKey): ?array
    {
        $values = Cache::many([$key, $leftKey]);
        $value = $values[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        [$raw, $at] = str_contains($value, '@') ? explode('@', $value, 2) : [$value, '0'];
        $answer = CupMembership::tryFrom($raw);
        if ($answer === null) {
            return null;
        }
        $askedAt = (int) $at;
        $leftAt = $values[$leftKey] ?? null;
        if ($answer === CupMembership::Member && is_numeric($leftAt) && $askedAt <= (int) $leftAt) {
            return null;
        }

        return [$answer, $askedAt];
    }
}
