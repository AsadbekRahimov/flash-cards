<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Events;

/**
 * The center's staff offered a learner membership (`organization_members.status = invited`, audit 2026-11-10 M2); raised after
 * commit. The listener sends the learner the "Join / Decline" DM.
 */
final readonly class OrganizationMemberInvited
{
    public function __construct(
        public int $organizationId,
        public int $learnerId,
        public int $memberId,
    ) {}
}
