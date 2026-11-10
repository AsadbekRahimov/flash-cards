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

    /**
     * Offered by the center's staff, waiting for the learner's "Join" in the bot (audit 2026-11-10 M2): no seat, no Pro,
     * not a member for any list or policy; `invited_at` / `invited_by_user_id` carry the offer.
     */
    case Invited = 'invited';

    /** The learner tapped "Decline" on a staff offer (`left_at` = the moment); a new offer is possible after a cooldown. */
    case Declined = 'declined';

    /** The states that end in the bot's hands (not active, not a past membership). */
    public function isPendingOffer(): bool
    {
        return $this === self::Invited;
    }
}
