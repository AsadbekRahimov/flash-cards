<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use RuntimeException;

/**
 * Why the cabinet could not issue a deep link or change the Telegram connection of a staff user.
 *
 *   rate_limited   429  too many links asked for in a short time (`retryAfter` seconds)
 *   not_linked     409  "disconnect" of a user who has no Telegram account connected
 */
final class TelegramLinkException extends RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';

    public const NOT_LINKED = 'not_linked';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, 'Too many Telegram links were requested; try again later.', max(1, $retryAfter));
    }

    public static function notLinked(int $userId): self
    {
        return new self(self::NOT_LINKED, "User #{$userId} has no Telegram account connected.");
    }

    public function httpStatus(): int
    {
        return $this->errorCode === self::RATE_LIMITED ? 429 : 409;
    }
}
