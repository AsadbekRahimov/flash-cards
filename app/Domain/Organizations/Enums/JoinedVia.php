<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/** `organization_members.joined_via` (docs/13 §1.3): how the membership was created. */
enum JoinedVia: string
{
    /** Invite code (`/start org_<code>`). Lives until "Leave the center" or removal in the cabinet. */
    case Invite = 'invite';

    /** Joined a Telegram group of the organization; ends when the learner leaves every group of it. */
    case Group = 'group';

    /** Added by staff / a superadmin. */
    case Admin = 'admin';
}
