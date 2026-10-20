<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'kind' => Offer::KIND_POST_ORG_DISCOUNT,
            'plan' => 'pro',
            'period' => 'month',
            'discount_pct' => 30,
            'expires_at' => now()->addDays(14),
            'redeemed_at' => null,
            'payment_order_id' => null,
            'source_organization_id' => null,
            'meta' => [],
        ];
    }

    public function redeemed(): static
    {
        return $this->state(fn (): array => ['redeemed_at' => now()->subDay()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
