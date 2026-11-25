<?php

declare(strict_types=1);

namespace App\Domain\Cup\Enums;

/**
 * What a fresh `getChatMember` said about a learner in the chat of a cup (docs/33 §4.2, §4.4).
 *
 * - `member`: creator, administrator, member, or restricted with `is_member = true`;
 * - `not_member`: left, kicked, restricted without `is_member`, or "user not found";
 * - `bot_removed`: Telegram answered 403 - the bot is not in the chat (the pause);
 * - `bot_not_admin`: another refusal while the bot is known not to be an administrator (Telegram does not answer about others);
 * - `unavailable`: network, 5xx, 429, or a refusal we cannot explain - never cached, never a "no".
 */
enum CupMembership: string
{
    case Member = 'member';
    case NotMember = 'not_member';
    case BotRemoved = 'bot_removed';
    case BotNotAdmin = 'bot_not_admin';
    case Unavailable = 'unavailable';

    /** A definite answer of Telegram that may be reused for the few seconds of the throttle. */
    public function isDefinite(): bool
    {
        return $this === self::Member || $this === self::NotMember || $this === self::BotRemoved;
    }
}
