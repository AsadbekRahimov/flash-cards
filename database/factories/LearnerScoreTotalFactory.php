<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Models\Learner;
use App\Models\LearnerScoreTotal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerScoreTotal> */
class LearnerScoreTotalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'category' => ScoreCategory::Free,
            'points' => 0,
            'long_words' => 0,
            'active_days' => 0,
            'first_day' => now()->toDateString(),
            'last_day' => now()->toDateString(),
            'participant' => true,
        ];
    }
}
