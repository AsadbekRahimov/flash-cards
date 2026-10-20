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
    /** The inviter reached `max_rewards_per_30d` / `max_rewards_lifetime` when the reward was due. */
    case ReferrerCapped = 'referrer_capped';
    /** An admin refused a flagged referral. */
    case Flagged = 'flagged';
}
