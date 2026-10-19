<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PromoCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PromoCode> */
class PromoCodeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => Str::upper(Str::random(10)),
            'plan' => 'pro',
            'duration_days' => 30,
            'max_redemptions' => null,
            'redemptions_count' => 0,
            'expires_at' => null,
            'is_active' => true,
            'created_by_user_id' => null,
            'note' => null,
        ];
    }
}
