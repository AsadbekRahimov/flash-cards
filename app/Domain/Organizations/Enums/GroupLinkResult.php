<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

use App\Domain\Organizations\Services\OrgGroupLinkService;

/**
 * Outcome of a group-link attempt from a Telegram chat ({@see OrgGroupLinkService::linkFromChat()}).
 * In the group the bot answers with ONE neutral text for every refusal (audit 2026-11-10 L8: the codes must not tell a
 * stranger whether a chat is known, taken or whose it is); the detailed value is stored on the token row and shown only
 * to the staff member in the cabinet ("last attempt").
 */
enum GroupLinkResult: string
{
    /** Linked (or already linked to this organization — idempotent). */
    case Linked = 'linked';

    /** The sender's Telegram account is not a VERIFIED, active staff account (nothing else is checked, no Telegram call). */
    case SenderUnknown = 'sender_unknown';

    /** Unknown / expired / used token (nothing to record). */
    case TokenInvalid = 'token_invalid';

    /** A live token opened by a verified staff account OTHER than the one it was issued to (recorded for the owner). */
    case SenderMismatch = 'sender_mismatch';

    /** The sender is no longer an owner of the token's organization, or the organization is suspended. */
    case NotOwner = 'not_owner';

    /** Telegram says the sender is not creator / administrator of the chat. */
    case NotChatAdmin = 'not_chat_admin';

    /** The chat already belongs to another organization. */
    case GroupTaken = 'group_taken';

    /** Telegram did not answer `getChatMember`; nothing was written, the token stays valid. */
    case TelegramUnavailable = 'telegram_unavailable';

    public function isSuccess(): bool
    {
        return $this === self::Linked;
    }
}
