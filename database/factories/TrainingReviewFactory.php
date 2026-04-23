<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrainingReview> */
class TrainingReviewFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'training_session_id' => TrainingSession::factory(),
            'student_id' => Student::factory(),
            'word_id' => Word::factory(),
            'quality' => fake()->numberBetween(0, 5),
            'time_spent_ms' => fake()->numberBetween(500, 15_000),
            'created_at' => now(),
        ];
    }
}
