<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

/** `referrals.reject_reason` (docs/13 §1.5). */
enum ReferralRejectReason: string
{
    /** The link was the learner's own code. */
    case SelfReferral = 'self';
    /** The learner existed before this `/start`: only new learners are referrals (first-touch stays). */
    case ExistingLearner = 'existing_learner';
    /** Legacy (before 2C.6): the inviter had reached the reward cap. Now only the inviter's side is skipped; no new rows get this reason. */
    case ReferrerCapped = 'referrer_capped';
    /** An admin refused a flagged referral. */
    case Flagged = 'flagged';
}
