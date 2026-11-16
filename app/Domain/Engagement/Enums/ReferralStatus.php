<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/**
 * `referrals.status` (docs/13 §1.5): `pending` → `activated` → `rewarded`, or `rejected` (+ `reject_reason`).
 * Since 2C.9 `rewarded` means "counted towards the inviter's milestones" (the friend themselves gets nothing but the
 * welcome trial); the value is kept for the stored rows and the clients.
 */
enum ReferralStatus: string
{
    case Pending = 'pending';
    case Activated = 'activated';
    case Rewarded = 'rewarded';
    case Rejected = 'rejected';

    /** A referral that counts as "invited" for the inviter (anything that was not refused). */
    public function counts(): bool
    {
        return $this !== self::Rejected;
    }
}
