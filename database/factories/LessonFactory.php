<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lesson> */
class LessonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'stage_id' => Stage::factory(),
            'number' => fake()->numberBetween(1, 100),
            'title' => fake()->sentence(3),
        ];
    }
}
