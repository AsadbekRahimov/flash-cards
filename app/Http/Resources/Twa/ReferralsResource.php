<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\ReferralOverview;
use App\Models\Referral;
use Illuminate\Http\Request;

/**
 * GET /api/twa/referrals (docs/04 A.12; docs/13 §7.1 "2C.9"): the learner's invite code and link, the activation rule,
 * the counts, the milestones (reached / rewarded) and the next one, the latest invited learners. Of other people only
 * a first name and the state of their referral leave the server — no ids, usernames or progress.
 *
 * @property ReferralOverview $resource
 */
final class ReferralsResource extends TwaResource
{
    /**
     * @return array{code: string, link: string|null, activation: array{days: int, reviews_per_day: int, window_days: int}, milestone_days: int, counts: array{invited: int, activated: int, counted: int}, milestones: list<array{friends: int, days: int, reached_at: string|null, rewarded: bool}>, next: array{friends: int, remaining: int}|null, referrals: list<array{first_name: string|null, status: string, linked_at: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'link' => $this->resource->link,
            'activation' => [
                'days' => $this->resource->activationDays,
                'reviews_per_day' => $this->resource->activationReviewsPerDay,
                'window_days' => $this->resource->activationWindowDays,
            ],
            'milestone_days' => $this->resource->milestoneDays,
            'counts' => [
                'invited' => $this->resource->invited,
                'activated' => $this->resource->activated,
                'counted' => $this->resource->counted,
            ],
            'milestones' => array_map(static fn (array $m): array => [
                'friends' => $m['friends'],
                'days' => $m['days'],
                'reached_at' => $m['reached_at']?->toIso8601String(),
                'rewarded' => $m['rewarded'],
            ], $this->resource->milestones),
            'next' => $this->resource->next,
            'referrals' => array_values($this->resource->referrals
                ->map(static fn (Referral $referral): array => [
                    'first_name' => $referral->referee?->first_name,
                    'status' => $referral->status,
                    'linked_at' => $referral->linked_at->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
