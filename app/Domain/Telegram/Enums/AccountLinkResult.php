<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

use App\Domain\Telegram\Services\TelegramAccountLinkService;

/**
 * Outcome of the two steps of `/start link_<token>` ({@see TelegramAccountLinkService::prompt()} and
 * {@see TelegramAccountLinkService::confirm()}). The bot tells the sender which one it was: the sender is the owner of the
 * Telegram account in question, so nothing is revealed to a third party — except for an unknown / dead token, where the text
 * does not say which of the two.
 */
enum AccountLinkResult: string
{
    /** The token is live: the bot asked "Connect this Telegram to the cabinet of X?" and remembered who it asked. */
    case Prompted = 'prompted';

    /** `users.telegram_user_id` + `telegram_verified_at` written. */
    case Linked = 'linked';

    /** The same account was already connected to this very user (a repeated tap on the link). */
    case AlreadyLinked = 'already_linked';

    /** Unknown token, expired, already used, or "Yes" from an account the question was not asked — one answer for all. */
    case TokenInvalid = 'token_invalid';

    /** This Telegram account is VERIFIED on another staff / admin user; it has to be disconnected there first. */
    case TelegramTaken = 'telegram_taken';

    public function isSuccess(): bool
    {
        return $this === self::Linked || $this === self::AlreadyLinked;
    }
}
