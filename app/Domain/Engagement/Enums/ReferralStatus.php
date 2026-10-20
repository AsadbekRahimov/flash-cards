<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `referrals.status` (docs/13 §1.5): `pending` → `activated` → `rewarded`, or `rejected` (+ `reject_reason`). */
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
