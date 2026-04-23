<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stage> */
class StageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'number' => $counter,
            'title' => "Stage {$counter}",
            'description' => fake()->sentence(),
        ];
    }
}
