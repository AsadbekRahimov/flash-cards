<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Why an owner could not link a Telegram group to the organization from the cabinet
 * (docs/13 §6.2: "a group where the bot is present AND the owner is creator / administrator").
 *
 *   group_not_found          404  the bot has never been added to a chat with this id
 *   group_taken              409  the group already belongs to another organization (a superadmin unlinks it there)
 *   owner_telegram_missing   409  the owner's account has no `telegram_user_id` (set it in the profile)
 *   not_chat_admin           403  the owner is not creator / administrator of the chat — somebody else's group
 *   telegram_unavailable     502  Telegram could not answer `getChatMember` (retry later)
 *   not_linked               404  unlink of a group that is not the organization's
 */
final class OrgGroupLinkException extends RuntimeException
{
    public const GROUP_NOT_FOUND = 'group_not_found';

    public const GROUP_TAKEN = 'group_taken';

    public const OWNER_TELEGRAM_MISSING = 'owner_telegram_missing';

    public const NOT_CHAT_ADMIN = 'not_chat_admin';

    public const TELEGRAM_UNAVAILABLE = 'telegram_unavailable';

    public const NOT_LINKED = 'not_linked';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function groupNotFound(int $chatId): self
    {
        return new self(self::GROUP_NOT_FOUND, "No group with chat id {$chatId} is known to the bot.");
    }

    public static function groupTaken(int $groupId): self
    {
        return new self(self::GROUP_TAKEN, "Group #{$groupId} already belongs to another organization.");
    }

    public static function ownerTelegramMissing(int $userId): self
    {
        return new self(self::OWNER_TELEGRAM_MISSING, "User #{$userId} has no Telegram account linked.");
    }

    public static function notChatAdmin(int $chatId, string $status): self
    {
        return new self(self::NOT_CHAT_ADMIN, "The owner is [{$status}] in chat {$chatId}, not an administrator.");
    }

    public static function telegramUnavailable(string $reason): self
    {
        return new self(self::TELEGRAM_UNAVAILABLE, "Telegram did not answer getChatMember: {$reason}");
    }

    public static function notLinked(int $groupId, int $organizationId): self
    {
        return new self(self::NOT_LINKED, "Group #{$groupId} is not linked to organization #{$organizationId}.");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::GROUP_NOT_FOUND, self::NOT_LINKED => 404,
            self::NOT_CHAT_ADMIN => 403,
            self::TELEGRAM_UNAVAILABLE => 502,
            default => 409,
        };
    }
}
