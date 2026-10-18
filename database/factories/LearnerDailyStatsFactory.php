<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerDailyStats> */
class LearnerDailyStatsFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'date' => now()->toDateString(),
            'reviews_count' => 0,
            'new_words_count' => 0,
            'correct_count' => 0,
            'time_spent_ms' => 0,
            'goal_met_at' => null,
        ];
    }
}
