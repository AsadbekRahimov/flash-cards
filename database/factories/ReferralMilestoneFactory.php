<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\ReferralMilestone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReferralMilestone> */
class ReferralMilestoneFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'referrer_learner_id' => Learner::factory(),
            'milestone' => 3,
            'days' => 7,
            'referral_id' => null,
            'reached_at' => now(),
            'granted_at' => null,
            'subscription_id' => null,
            'skipped' => null,
        ];
    }

    /** Reached and granted (or skipped for `$skipped`). */
    public function granted(?string $skipped = null, ?int $subscriptionId = null): static
    {
        return $this->state(fn (): array => [
            'granted_at' => now(),
            'subscription_id' => $skipped === null ? $subscriptionId : null,
            'skipped' => $skipped,
        ]);
    }
}
