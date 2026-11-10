<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

use App\Domain\Telegram\Services\TelegramAccountLinkService;

/**
 * Outcome of `/start link_<token>` ({@see TelegramAccountLinkService::linkFromStart()}).
 * The bot tells the sender which one it was: the sender is the owner of the Telegram account in question, so nothing is
 * revealed to a third party — except for an unknown / dead token, where the text does not say which of the two.
 */
enum AccountLinkResult: string
{
    /** `users.telegram_user_id` + `telegram_verified_at` written. */
    case Linked = 'linked';

    /** The same account was already connected to this very user (a repeated tap on the link). */
    case AlreadyLinked = 'already_linked';

    /** Unknown token, expired, or already used — one answer for all three. */
    case TokenInvalid = 'token_invalid';

    /** This Telegram account is connected to another staff / admin user; it has to be disconnected there first. */
    case TelegramTaken = 'telegram_taken';

    public function isSuccess(): bool
    {
        return $this === self::Linked || $this === self::AlreadyLinked;
    }
}
