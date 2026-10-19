<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Telegram answered 403 Forbidden (or 400 "chat not found"): the user blocked the bot,
 * never started a dialog with it, or the account was deactivated / deleted. Retrying
 * will never help.
 */
final class TelegramForbiddenException extends RuntimeException
{
    public const REASON_BOT_BLOCKED = 'bot_blocked';

    public const REASON_NOT_STARTED = 'not_started';

    public const REASON_USER_DEACTIVATED = 'user_deactivated';

    public const REASON_FORBIDDEN = 'forbidden';

    /** Not a 403 but a 400 "chat not found": Telegram's answer for a dialog that no longer exists (account deleted). */
    public const REASON_CHAT_NOT_FOUND = 'chat_not_found';

    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Telegram forbade the request: '.$reason, 403, $previous);
    }

    public static function fromDescription(string $description, ?Throwable $previous = null): self
    {
        $description = strtolower($description);

        $reason = match (true) {
            str_contains($description, 'blocked') => self::REASON_BOT_BLOCKED,
            str_contains($description, "can't initiate") => self::REASON_NOT_STARTED,
            str_contains($description, 'deactivated') => self::REASON_USER_DEACTIVATED,
            str_contains($description, 'chat not found') => self::REASON_CHAT_NOT_FOUND,
            default => self::REASON_FORBIDDEN,
        };

        return new self($reason, $previous);
    }
}
