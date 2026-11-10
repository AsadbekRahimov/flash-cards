<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/**
 * What the center's staff see of an invitation (`organization_member_invitations`, review finding B1 of the audit 2026-11-10).
 * Four states and no more: a learner who declined, a learner nobody could reach and a handle that matched nobody all read
 * `pending` until the TTL and `expired` after it. `accepted` appears only when the learner joined (they are then an ordinary
 * member of the list anyway); `revoked` is the staff member's own action.
 */
enum MemberInvitationState: string
{
    case Pending = 'pending';

    case Expired = 'expired';

    case Accepted = 'accepted';

    case Revoked = 'revoked';
}
