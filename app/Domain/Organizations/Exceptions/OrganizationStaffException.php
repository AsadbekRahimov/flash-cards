<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Exceptions;

use RuntimeException;

/**
 * Why a staff change of the `/org` cabinet was refused (docs/13 §2.3 OrganizationStaffService).
 *
 *   already_staff   409  the email already belongs to staff of this organization (a repeated invitation)
 *   not_staff       404  the row is not staff of this organization
 *   last_owner      409  the last owner cannot be removed or demoted
 *   foreign_group   422  a group of another organization (or none) cannot be assigned
 *   invalid_email   422  not an email address
 */
final class OrganizationStaffException extends RuntimeException
{
    public const ALREADY_STAFF = 'already_staff';

    public const NOT_STAFF = 'not_staff';

    public const LAST_OWNER = 'last_owner';

    public const FOREIGN_GROUP = 'foreign_group';

    public const INVALID_EMAIL = 'invalid_email';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function alreadyStaff(string $email, int $organizationId): self
    {
        return new self(self::ALREADY_STAFF, "[{$email}] is already staff of organization #{$organizationId}.");
    }

    public static function notStaff(int $organizationId): self
    {
        return new self(self::NOT_STAFF, "The row is not staff of organization #{$organizationId}.");
    }

    public static function lastOwner(int $organizationId): self
    {
        return new self(self::LAST_OWNER, "Organization #{$organizationId} must keep at least one owner.");
    }

    public static function foreignGroup(int $groupId, int $organizationId): self
    {
        return new self(self::FOREIGN_GROUP, "Group #{$groupId} does not belong to organization #{$organizationId}.");
    }

    public static function invalidEmail(string $email): self
    {
        return new self(self::INVALID_EMAIL, "[{$email}] is not an email address.");
    }

    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::NOT_STAFF => 404,
            self::FOREIGN_GROUP, self::INVALID_EMAIL => 422,
            default => 409,
        };
    }
}
