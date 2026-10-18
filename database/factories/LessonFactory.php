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
        // Monotonic counter: random numbers collided on UNIQUE(stage_id, number).
        static $counter = 0;
        $counter++;

        return [
            'stage_id' => Stage::factory(),
            'number' => $counter,
            'title' => fake()->sentence(3),
        ];
    }
}
