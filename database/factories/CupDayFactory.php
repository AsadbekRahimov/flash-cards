<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cup;
use App\Models\CupDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CupDay> */
class CupDayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cup_id' => Cup::factory(),
            'day' => 1,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDay(),
        ];
    }
}
