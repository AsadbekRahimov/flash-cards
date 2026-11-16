<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Services;

use App\Domain\Engagement\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use Carbon\CarbonImmutable;

/**
 * The milestones of one inviter for the admin panel (learner page → Referrals; docs/13 §7.1 "2C.9"): how many friends are
 * counted and which milestones were reached, when, and what came of the reward (granted until a date, owed, or skipped
 * with a reason). Two statements whatever the number of milestones; the page shows it above the table of invited learners.
 *
 * @phpstan-type Reached array{milestone: int, days: int, reached_at: CarbonImmutable, granted_at: CarbonImmutable|null, skipped: string|null, until: CarbonImmutable|null}
 */
final class ReferralMilestoneReport
{
    /** @return array{counted: int, reached: list<Reached>} */
    public function forReferrer(int $learnerId): array
    {
        $counted = Referral::query()
            ->where('referrer_learner_id', $learnerId)
            ->where('status', ReferralStatus::Rewarded->value)
            ->count();

        $reached = [];
        $rows = ReferralMilestone::query()
            ->leftJoin('subscriptions', 'subscriptions.id', '=', 'referral_milestones.subscription_id')
            ->where('referral_milestones.referrer_learner_id', $learnerId)
            ->orderBy('referral_milestones.milestone')
            ->get(['referral_milestones.*', 'subscriptions.current_period_ends_at as period_ends_at']);

        foreach ($rows as $row) {
            $until = $row->getAttribute('period_ends_at');

            $reached[] = [
                'milestone' => $row->milestone,
                'days' => $row->days,
                'reached_at' => CarbonImmutable::instance($row->reached_at),
                'granted_at' => $row->granted_at === null ? null : CarbonImmutable::instance($row->granted_at),
                'skipped' => $row->skipped,
                'until' => is_string($until) ? CarbonImmutable::parse($until) : null,
            ];
        }

        return ['counted' => $counted, 'reached' => $reached];
    }
}
