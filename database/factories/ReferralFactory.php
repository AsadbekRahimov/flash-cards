<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ReferralStatus;
use App\Models\Learner;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Referral> */
class ReferralFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'referrer_learner_id' => Learner::factory(),
            'referee_learner_id' => Learner::factory(),
            'status' => ReferralStatus::Pending->value,
            'reject_reason' => null,
            'linked_at' => now(),
            'activated_at' => null,
            'rewarded_at' => null,
            'flagged_at' => null,
            'meta' => [],
        ];
    }

    public function activated(): static
    {
        return $this->state(fn (): array => [
            'status' => ReferralStatus::Activated->value,
            'activated_at' => now(),
        ]);
    }

    /** Both sides granted; `linked_at`/`rewarded_at` default to now (pass one for "30 days ago" cases). */
    public function rewarded(?\DateTimeInterface $at = null): static
    {
        return $this->state(fn (): array => [
            'status' => ReferralStatus::Rewarded->value,
            'linked_at' => $at ?? now(),
            'activated_at' => $at ?? now(),
            'rewarded_at' => $at ?? now(),
            'meta' => [
                'rewards' => [
                    Referral::SIDE_REFEREE => ['subscription_id' => null, 'skipped' => 'perpetual'],
                    Referral::SIDE_REFERRER => ['subscription_id' => null, 'skipped' => 'perpetual'],
                ],
                'reward_subscription_ids' => [],
            ],
        ]);
    }

    public function flagged(): static
    {
        return $this->state(fn (): array => ['flagged_at' => now()]);
    }
}
