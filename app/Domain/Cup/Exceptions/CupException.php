<?php

declare(strict_types=1);

namespace App\Domain\Cup\Exceptions;

use App\Domain\Cup\Support\DisplayName;
use App\Domain\Game\Exceptions\GameException;
use RuntimeException;

/**
 * Why an action of a cup was refused (docs/33 §7.2). Same shape as {@see GameException}: `errorCode` is the stable code, `httpStatus()`
 * the status of the TWA answer, `details` goes into `error.details`. A controller answers
 * `ApiErrorResource::respond($e->httpStatus(), $e->errorCode, $e->getMessage(), $e->details)`; the bot answers a toast.
 *
 *   403  cup_unavailable (the feature is off for the chat) · cup_not_member (no cup, a stranger's cup, not in the chat - ONE answer)
 *        · cup_not_chat_admin
 *   404  cup_round_not_found (a stranger's attempt looks the same as a missing one)
 *   409  cup_paused {reason} · cup_unverifiable {reason: bot_not_admin} · cup_bot_not_admin · cup_already_active {cup_id}
 *        · cup_not_open (a draft: nobody joins it yet) · cup_already_published (level / language after the publication) · cup_not_started · cup_round_unavailable (the cup has no current day)
 *        · cup_round_done {points} · cup_round_expired {points} · element_not_current {current} · answer_already_accepted {verdict}
 *        · cup_rename_limit
 *   410  cup_closed (finished or canceled)
 *   422  cup_material_unavailable · cup_display_name_invalid {reason} · cup_level_invalid · invalid_answer_shape · unknown_element_id · unknown_option_id
 *   429  cup_check_busy (another request of the same learner is checking the membership right now)
 *   503  cup_unverifiable {reason: telegram_unavailable}
 *
 * Duels (docs/33 §5.7, §11.9):
 *   403  cup_duel_unavailable (the participant cannot be challenged / this challenge cannot be accepted by you - no reason, a block
 *        looks the same) · cup_duel_not_recipient (declining a challenge that is not addressed to you)
 *   404  cup_duel_not_found (no duel, a duel of another cup or a stranger's, a challenge addressed to somebody else)
 *   409  cup_duel_disabled · cup_duel_closed {status, reason} · cup_duel_own · cup_duel_already_open {duel_id}
 *        · cup_duel_rating_changed {rated} · cup_duel_limit {scope, limit} · cup_duel_opponent_busy
 *   422  cup_duel_self · cup_duel_mode_invalid
 */
final class CupException extends RuntimeException
{
    public const UNAVAILABLE = 'cup_unavailable';

    public const NOT_MEMBER = 'cup_not_member';

    public const NOT_CHAT_ADMIN = 'cup_not_chat_admin';

    public const ROUND_NOT_FOUND = 'cup_round_not_found';

    public const PAUSED = 'cup_paused';

    public const UNVERIFIABLE = 'cup_unverifiable';

    public const BOT_NOT_ADMIN = 'cup_bot_not_admin';

    public const ALREADY_ACTIVE = 'cup_already_active';

    public const NOT_OPEN = 'cup_not_open';

    public const NOT_STARTED = 'cup_not_started';

    public const ALREADY_PUBLISHED = 'cup_already_published';

    public const ROUND_UNAVAILABLE = 'cup_round_unavailable';

    public const ROUND_DONE = 'cup_round_done';

    public const ROUND_EXPIRED = 'cup_round_expired';

    public const ELEMENT_NOT_CURRENT = 'element_not_current';

    public const ANSWER_ALREADY_ACCEPTED = 'answer_already_accepted';

    public const RENAME_LIMIT = 'cup_rename_limit';

    public const CLOSED = 'cup_closed';

    public const MATERIAL_UNAVAILABLE = 'cup_material_unavailable';

    public const DISPLAY_NAME_INVALID = 'cup_display_name_invalid';

    public const LEVEL_INVALID = 'cup_level_invalid';

    public const INVALID_ANSWER_SHAPE = 'invalid_answer_shape';

    public const UNKNOWN_ELEMENT_ID = 'unknown_element_id';

    public const UNKNOWN_OPTION_ID = 'unknown_option_id';

    public const CHECK_BUSY = 'cup_check_busy';

    public const DUEL_DISABLED = 'cup_duel_disabled';

    public const DUEL_UNAVAILABLE = 'cup_duel_unavailable';

    public const DUEL_NOT_RECIPIENT = 'cup_duel_not_recipient';

    public const DUEL_NOT_FOUND = 'cup_duel_not_found';

    public const DUEL_CLOSED = 'cup_duel_closed';

    public const DUEL_OWN = 'cup_duel_own';

    public const DUEL_ALREADY_OPEN = 'cup_duel_already_open';

    public const DUEL_RATING_CHANGED = 'cup_duel_rating_changed';

    public const DUEL_LIMIT = 'cup_duel_limit';

    public const DUEL_OPPONENT_BUSY = 'cup_duel_opponent_busy';

    public const DUEL_SELF = 'cup_duel_self';

    public const DUEL_MODE_INVALID = 'cup_duel_mode_invalid';

    /** `cup_duel_limit.scope`: challenges of a participant a day / to the same participant a day / public ones of a participant / of a chat. */
    public const LIMIT_DAILY = 'daily';

    public const LIMIT_PAIR = 'pair';

    public const LIMIT_PUBLIC_PARTICIPANT = 'public_participant';

    public const LIMIT_PUBLIC_CHAT = 'public_chat';

    public const REASON_BOT_NOT_ADMIN = 'bot_not_admin';

    public const REASON_TELEGRAM_UNAVAILABLE = 'telegram_unavailable';

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, 'The cup is not available in this chat.');
    }

    public static function notMember(): self
    {
        return new self(self::NOT_MEMBER, 'The cup is open to the members of its chat only.');
    }

    public static function notChatAdmin(): self
    {
        return new self(self::NOT_CHAT_ADMIN, 'Only an administrator of the chat can do this.');
    }

    public static function roundNotFound(): self
    {
        return new self(self::ROUND_NOT_FOUND, 'The round was not found.');
    }

    public static function paused(string $reason): self
    {
        return new self(self::PAUSED, 'The cup is paused.', ['reason' => $reason]);
    }

    public static function botNotAdminUnverifiable(): self
    {
        return new self(self::UNVERIFIABLE, 'The bot cannot check the members of the chat.', ['reason' => self::REASON_BOT_NOT_ADMIN]);
    }

    public static function telegramUnavailable(): self
    {
        return new self(self::UNVERIFIABLE, 'Telegram could not be asked; try again.', ['reason' => self::REASON_TELEGRAM_UNAVAILABLE]);
    }

    public static function botNotAdmin(): self
    {
        return new self(self::BOT_NOT_ADMIN, 'Make the bot an administrator of the chat.');
    }

    public static function alreadyActive(int $cupId): self
    {
        return new self(self::ALREADY_ACTIVE, 'The chat already has a cup.', ['cup_id' => $cupId]);
    }

    public static function notOpen(): self
    {
        return new self(self::NOT_OPEN, 'The cup is not published yet.');
    }

    public static function alreadyPublished(): self
    {
        return new self(self::ALREADY_PUBLISHED, 'The level and the language are fixed at the publication.');
    }

    public static function notStarted(): self
    {
        return new self(self::NOT_STARTED, 'The cup has not started yet.');
    }

    public static function roundUnavailable(): self
    {
        return new self(self::ROUND_UNAVAILABLE, 'There is no round to play now.');
    }

    public static function roundDone(int $points): self
    {
        return new self(self::ROUND_DONE, 'The round of the day is played.', ['points' => $points]);
    }

    public static function roundExpired(int $points): self
    {
        return new self(self::ROUND_EXPIRED, 'The time of the round is over.', ['points' => $points]);
    }

    public static function elementNotCurrent(string $current): self
    {
        return new self(self::ELEMENT_NOT_CURRENT, 'Answer the current task.', ['current' => $current]);
    }

    public static function answerAlreadyAccepted(string $verdict): self
    {
        return new self(self::ANSWER_ALREADY_ACCEPTED, 'The task is already answered.', ['verdict' => $verdict]);
    }

    public static function renameLimit(): self
    {
        return new self(self::RENAME_LIMIT, 'The name can no longer be changed in this cup.');
    }

    public static function closed(): self
    {
        return new self(self::CLOSED, 'The cup is over.');
    }

    public static function materialUnavailable(): self
    {
        return new self(self::MATERIAL_UNAVAILABLE, 'There are not enough words of this level for a cup.');
    }

    /**
     * `reason` ({@see DisplayName}): `empty` | `too_long` | `marks` | `link` | `mention` | `command` | `tag` - Telegram would turn the
     * last four into a link in the chat.
     */
    public static function displayNameInvalid(string $reason = DisplayName::REASON_EMPTY): self
    {
        return new self(self::DISPLAY_NAME_INVALID, match ($reason) {
            DisplayName::REASON_EMPTY, DisplayName::REASON_TOO_LONG => 'The name must be 1 to 32 characters.',
            DisplayName::REASON_MARKS => 'The name has too many combining marks.',
            default => 'The name must not look like a link, a @mention, a /command or a #tag.',
        }, ['reason' => $reason]);
    }

    public static function levelInvalid(): self
    {
        return new self(self::LEVEL_INVALID, 'Unknown level.');
    }

    public static function invalidAnswerShape(): self
    {
        return new self(self::INVALID_ANSWER_SHAPE, 'The answer has the wrong shape.');
    }

    public static function unknownElementId(): self
    {
        return new self(self::UNKNOWN_ELEMENT_ID, 'Unknown task.');
    }

    public static function unknownOptionId(): self
    {
        return new self(self::UNKNOWN_OPTION_ID, 'Unknown option.');
    }

    public static function checkBusy(): self
    {
        return new self(self::CHECK_BUSY, 'The membership is being checked; try again in a few seconds.');
    }

    public static function duelDisabled(): self
    {
        return new self(self::DUEL_DISABLED, 'Duels are not available now.');
    }

    public static function duelUnavailable(): self
    {
        return new self(self::DUEL_UNAVAILABLE, 'This duel is not available.');
    }

    public static function duelNotRecipient(): self
    {
        return new self(self::DUEL_NOT_RECIPIENT, 'The challenge is not addressed to you.');
    }

    public static function duelNotFound(): self
    {
        return new self(self::DUEL_NOT_FOUND, 'The duel was not found.');
    }

    public static function duelClosed(string $status, ?string $reason = null): self
    {
        return new self(self::DUEL_CLOSED, 'The challenge is no longer open.', ['status' => $status, 'reason' => $reason]);
    }

    public static function duelOwn(): self
    {
        return new self(self::DUEL_OWN, 'You cannot accept your own challenge.');
    }

    public static function duelAlreadyOpen(int $duelId): self
    {
        return new self(self::DUEL_ALREADY_OPEN, 'You already have an open challenge.', ['duel_id' => $duelId]);
    }

    public static function duelRatingChanged(bool $rated): self
    {
        return new self(self::DUEL_RATING_CHANGED, $rated ? 'The duel is rated now.' : 'The duel is a friendly match now.', ['rated' => $rated]);
    }

    public static function duelLimit(string $scope, int $limit): self
    {
        return new self(self::DUEL_LIMIT, 'Too many challenges for today.', ['scope' => $scope, 'limit' => $limit]);
    }

    public static function duelOpponentBusy(): self
    {
        return new self(self::DUEL_OPPONENT_BUSY, 'The challenger is playing another match right now.');
    }

    public static function duelSelf(): self
    {
        return new self(self::DUEL_SELF, 'You cannot challenge yourself.');
    }

    public static function duelModeInvalid(): self
    {
        return new self(self::DUEL_MODE_INVALID, 'This mode is not played in a duel.');
    }

    public function httpStatus(): int
    {
        return match (true) {
            in_array($this->errorCode, [self::UNAVAILABLE, self::NOT_MEMBER, self::NOT_CHAT_ADMIN, self::DUEL_UNAVAILABLE, self::DUEL_NOT_RECIPIENT], true) => 403,
            in_array($this->errorCode, [self::ROUND_NOT_FOUND, self::DUEL_NOT_FOUND], true) => 404,
            $this->errorCode === self::CLOSED => 410,
            in_array($this->errorCode, [self::MATERIAL_UNAVAILABLE, self::DISPLAY_NAME_INVALID, self::LEVEL_INVALID, self::INVALID_ANSWER_SHAPE, self::UNKNOWN_ELEMENT_ID, self::UNKNOWN_OPTION_ID, self::DUEL_SELF, self::DUEL_MODE_INVALID], true) => 422,
            $this->errorCode === self::CHECK_BUSY => 429,
            $this->errorCode === self::UNVERIFIABLE && ($this->details['reason'] ?? null) === self::REASON_TELEGRAM_UNAVAILABLE => 503,
            default => 409,
        };
    }
}
