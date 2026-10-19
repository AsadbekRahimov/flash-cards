<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromoRedemption> */
class PromoRedemptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'promo_code_id' => PromoCode::factory(),
            'learner_id' => Learner::factory(),
            'subscription_id' => null,
            'redeemed_at' => now(),
        ];
    }
}
