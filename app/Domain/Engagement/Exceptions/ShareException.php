<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Why a message to share was not prepared (docs/36 §1в, docs/04 A.22). `errorCode` is what the client sees, `reason` is for the logs
 * only (it never goes into the answer: "someone else's" must look exactly like "none"). In every case the Mini App falls back to
 * `t.me/share/url`.
 *
 *   404  share_subject_not_found - no such achievement / city, not unlocked / not beaten, or someone else's: one code
 *   403  share_unavailable       - sharing is off, the learner is blocked or has no Telegram id, the bot has no username, the kind is
 *                                  not there yet, the game is closed
 *   503  share_failed {retry_after?} - Telegram refused, did not answer, answered 429, the bot is paused after a 429, or a parallel
 *                                  request still holds the lock
 */
final class ShareException extends RuntimeException
{
    public const NOT_FOUND = 'share_subject_not_found';

    public const UNAVAILABLE = 'share_unavailable';

    public const FAILED = 'share_failed';

    private function __construct(
        public readonly string $errorCode,
        public readonly string $reason,
        string $message,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'not_found', 'Nothing to share.');
    }

    /** @param  string  $reason  disabled | learner_blocked | no_telegram_id | bot_username_missing | kind_not_available | game_unavailable */
    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, $reason, 'Sharing is unavailable.');
    }

    /** @param  string  $reason  telegram_refused | telegram_unreachable | telegram_rate_limited | telegram_paused | busy */
    public static function failed(string $reason, ?int $retryAfter = null, ?Throwable $previous = null): self
    {
        return new self(self::FAILED, $reason, 'Could not prepare the message.', $retryAfter, $previous);
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_FOUND => 404,
            self::UNAVAILABLE => 403,
            default => 503,
        };
    }

    /** @return array<string, int> `error.details` of the answer: only `retry_after`, when it is known */
    public function details(): array
    {
        return $this->retryAfter === null ? [] : ['retry_after' => $this->retryAfter];
    }
}
