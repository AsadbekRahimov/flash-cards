<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\Organization;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscriber_type' => 'learner',
            'subscriber_id' => Learner::factory(),
            'plan' => 'pro',
            'status' => 'active',
            'provider' => 'manual',
            'provider_ref' => null,
            'starts_at' => now(),
            'current_period_ends_at' => now()->addDays(30),
            'grace_until' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'seats_limit' => null,
            'granted_by_user_id' => null,
            'note' => null,
            'meta' => [],
        ];
    }

    public function forOrganization(?Organization $organization = null): static
    {
        return $this->state(fn (): array => [
            'subscriber_type' => 'organization',
            'subscriber_id' => $organization ?? Organization::factory(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'expired',
            'starts_at' => now()->subDays(60),
            'current_period_ends_at' => now()->subDays(30),
            'ended_at' => now()->subDays(30),
        ]);
    }
}
