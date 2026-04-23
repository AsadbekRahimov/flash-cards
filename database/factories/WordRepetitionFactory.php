<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WordRepetition> */
class WordRepetitionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quality = fake()->numberBetween(0, 5);
        $reps = $quality >= 3 ? fake()->numberBetween(1, 6) : 0;
        $interval = $reps === 0 ? 0 : fake()->numberBetween(1, 30);

        return [
            'student_id' => Student::factory(),
            'word_id' => Word::factory(),
            'repetitions' => $reps,
            'interval_days' => $interval,
            'easiness_factor' => fake()->randomFloat(2, 1.30, 2.80),
            'last_quality' => $quality,
            'last_reviewed_at' => now()->subDays(fake()->numberBetween(0, 10)),
            'next_review_at' => now()->addDays(fake()->numberBetween(-2, 14)),
            'is_hard' => $quality <= 2,
            'meta' => [],
        ];
    }

    public function due(): static
    {
        return $this->state(fn (): array => [
            'next_review_at' => now()->subHours(fake()->numberBetween(1, 24)),
        ]);
    }

    public function hard(): static
    {
        return $this->state(fn (): array => [
            'is_hard' => true,
            'last_quality' => 1,
        ]);
    }
}
