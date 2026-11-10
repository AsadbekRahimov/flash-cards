<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Events;

/**
 * The center's staff invited a learner the bot may message (`organization_member_invitations.dm_sent_at` reserved, audit
 * 2026-11-10 M2); raised after commit. The listener sends the learner the "Join / Decline" DM. Not raised for a handle that
 * matched nobody or a learner inside a DM cooldown — the cabinet cannot tell the difference.
 */
final readonly class OrganizationMemberInvited
{
    public function __construct(
        public int $organizationId,
        public int $invitationId,
        public int $learnerId,
    ) {}
}
