<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Why a learner could not join / leave an organization (docs/13 §2.3). Same shape as the
 * billing exceptions: `errorCode` is the stable machine-readable code, `httpStatus()` the
 * recommended TWA status.
 *
 *   invite_invalid          404  no such code, or the invite was deactivated
 *   invite_expired          410  `expires_at` passed
 *   invite_exhausted        409  `max_uses` reached
 *   organization_suspended  409  only an `active` organization accepts members
 *   not_a_member            404  leave / remove of a learner without an active membership
 *   membership_not_found    404  "my centers" API: no such active membership of THIS learner
 *   membership_via_group    409  a `joined_via = group` membership cannot be left by the learner (it returns with the group)
 *   invite_not_pending      410  "Join" / "Decline" / "revoke" of a staff offer that is no longer waiting (answered, revoked, expired)
 *   rate_limited            429  the center's staff sent too many offers in an hour (`retryAfter` seconds)
 */
final class OrganizationMembershipException extends RuntimeException
{
    public const INVITE_INVALID = 'invite_invalid';

    public const INVITE_EXPIRED = 'invite_expired';

    public const INVITE_EXHAUSTED = 'invite_exhausted';

    public const ORGANIZATION_SUSPENDED = 'organization_suspended';

    public const NOT_A_MEMBER = 'not_a_member';

    public const MEMBERSHIP_NOT_FOUND = 'membership_not_found';

    public const MEMBERSHIP_VIA_GROUP = 'membership_via_group';

    public const INVITE_NOT_PENDING = 'invite_not_pending';

    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }

    public static function inviteNotPending(int $memberId): self
    {
        return new self(self::INVITE_NOT_PENDING, "Membership offer #{$memberId} is not waiting for an answer.");
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, 'Too many membership offers were sent; try again later.', max(1, $retryAfter));
    }

    public static function inviteInvalid(): self
    {
        return new self(self::INVITE_INVALID, 'The invite code is unknown or no longer active.');
    }

    public static function inviteExpired(string $code): self
    {
        return new self(self::INVITE_EXPIRED, "Invite [{$code}] has expired.");
    }

    public static function inviteExhausted(string $code): self
    {
        return new self(self::INVITE_EXHAUSTED, "Invite [{$code}] has no uses left.");
    }

    public static function organizationSuspended(int $organizationId): self
    {
        return new self(self::ORGANIZATION_SUSPENDED, "Organization #{$organizationId} is suspended and accepts no members.");
    }

    public static function notAMember(int $learnerId, int $organizationId): self
    {
        return new self(self::NOT_A_MEMBER, "Learner #{$learnerId} is not an active member of organization #{$organizationId}.");
    }

    public static function membershipNotFound(int $memberId): self
    {
        return new self(self::MEMBERSHIP_NOT_FOUND, "No active membership #{$memberId} for this learner.");
    }

    public static function membershipViaGroup(int $memberId): self
    {
        return new self(self::MEMBERSHIP_VIA_GROUP, "Membership #{$memberId} came through a group of the organization; leave the group instead.");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::INVITE_INVALID, self::NOT_A_MEMBER, self::MEMBERSHIP_NOT_FOUND => 404,
            self::INVITE_EXPIRED, self::INVITE_NOT_PENDING => 410,
            self::RATE_LIMITED => 429,
            default => 409,
        };
    }
}
