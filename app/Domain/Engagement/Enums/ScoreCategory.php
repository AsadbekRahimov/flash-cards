<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Enums;

use App\Domain\Billing\Enums\Plan;

/**
 * The table a learner competes in for a week or a month (docs/28, docs/32 §2.3): fixed once at the first credit of the period
 * from the plan in force at that moment. A trial Pro and a seat of an organisation are Pro (owner's decision В4, docs/31 §5).
 */
enum ScoreCategory: string
{
    case Free = 'free';
    case Pro = 'pro';

    public static function fromPlan(Plan $plan): self
    {
        return match ($plan) {
            Plan::Pro => self::Pro,
            Plan::Free => self::Free,
        };
    }
}
