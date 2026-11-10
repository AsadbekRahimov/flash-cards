<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use App\Domain\Organizations\Enums\GroupLinkResult;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use RuntimeException;

/**
 * Why the cabinet could not act on a Telegram group of the organization (docs/13 §6.2). Since the audit of 2026-11-10 (H1,
 * P1, L8) a group is linked only from the chat itself ({@see OrgGroupLinkService::linkFromChat()},
 * outcomes in {@see GroupLinkResult}); the cabinet issues the link and shows the result.
 *
 *   not_owner                  403  only an owner of the center issues group links
 *   owner_telegram_unverified  409  the owner has not connected their Telegram through the bot (Profile → "Connect Telegram")
 *   rate_limited               429  too many links asked for
 *   not_linked                 404  unlink / course change of a group that is not the organization's
 *   course_unavailable         422  the course is not published, is private or does not exist (the single availability rule)
 */
final class OrgGroupLinkException extends RuntimeException
{
    public const NOT_OWNER = 'not_owner';

    public const OWNER_TELEGRAM_UNVERIFIED = 'owner_telegram_unverified';

    public const RATE_LIMITED = 'rate_limited';

    public const NOT_LINKED = 'not_linked';

    public const COURSE_UNAVAILABLE = 'course_unavailable';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public static function notOwner(int $userId, int $organizationId): self
    {
        return new self(self::NOT_OWNER, "User #{$userId} is not an owner of organization #{$organizationId}.");
    }

    public static function ownerTelegramUnverified(int $userId): self
    {
        return new self(self::OWNER_TELEGRAM_UNVERIFIED, "User #{$userId} has no Telegram account verified through the bot.");
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, 'Too many group links were requested; try again later.', max(1, $retryAfter));
    }

    public static function notLinked(int $groupId, int $organizationId): self
    {
        return new self(self::NOT_LINKED, "Group #{$groupId} is not linked to organization #{$organizationId}.");
    }

    public static function courseUnavailable(int $courseId): self
    {
        return new self(self::COURSE_UNAVAILABLE, "Course #{$courseId} is not available to groups.");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_LINKED => 404,
            self::NOT_OWNER => 403,
            self::COURSE_UNAVAILABLE => 422,
            self::RATE_LIMITED => 429,
            default => 409,
        };
    }
}
