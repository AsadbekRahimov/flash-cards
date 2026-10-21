<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Engagement\DTO\ReferralOverview;
use App\Models\Referral;
use Illuminate\Http\Request;

/**
 * GET /api/twa/referrals (docs/04 A.12): the learner's invite code and link, the rule and the reward,
 * the counts and the latest invited learners. Of other people only a first name and the state of
 * their referral leave the server — no ids, usernames or progress.
 *
 * @property ReferralOverview $resource
 */
final class ReferralsResource extends TwaResource
{
    /**
     * @return array{code: string, link: string|null, reward_days: int, activation: array{days: int, reviews_per_day: int, window_days: int}, counts: array{invited: int, activated: int, rewarded: int, capped: int}, referrals: list<array{first_name: string|null, status: string, linked_at: string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'link' => $this->resource->link,
            'reward_days' => $this->resource->rewardDays,
            'activation' => [
                'days' => $this->resource->activationDays,
                'reviews_per_day' => $this->resource->activationReviewsPerDay,
                'window_days' => $this->resource->activationWindowDays,
            ],
            'counts' => [
                'invited' => $this->resource->invited,
                'activated' => $this->resource->activated,
                'rewarded' => $this->resource->rewarded,
                'capped' => $this->resource->capped,
            ],
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
