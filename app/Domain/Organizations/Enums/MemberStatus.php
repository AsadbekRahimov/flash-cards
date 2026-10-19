<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Enums;

/** `organization_members.status` (docs/13 §1.3). */
enum MemberStatus: string
{
    case Active = 'active';

    /** The learner left on their own ("Leave the center", or left every group of the organization). */
    case Left = 'left';

    /** Removed by a teacher / owner / superadmin. */
    case Removed = 'removed';
}
