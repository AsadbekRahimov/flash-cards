<?php

declare(strict_types=1);

namespace App\Domain\Administration\Exceptions;

use App\Domain\Administration\Services\OrganizationGroupLinker;
use DomainException;

/**
 * Why {@see OrganizationGroupLinker} refused to link a group. It stays a plain
 * `DomainException` for the callers that catch that (OrgGroupLinkService); `errorCode` is the stable code and the
 * English message is for logs — the admin panel writes the same error in Russian from the code (AdminErrors).
 *
 *   group_taken  the group already belongs to another organization (unlink it there first)
 */
final class OrganizationGroupLinkException extends DomainException
{
    public const GROUP_TAKEN = 'group_taken';

    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function groupTaken(): self
    {
        return new self(self::GROUP_TAKEN, 'The group already belongs to another organization.');
    }
}
