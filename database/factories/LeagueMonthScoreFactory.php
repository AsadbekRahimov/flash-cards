<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeagueMonth;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeagueMonthScore> */
class LeagueMonthScoreFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'league_month_id' => LeagueMonth::factory(),
            'learner_id' => Learner::factory(),
            'points' => 0,
            'raw_points' => 0,
            'active_days' => 0,
            'capped_days' => 0,
            'max_day_points' => 0,
            'reviews' => 0,
            'correct' => 0,
            'new_words' => 0,
            'goal_days' => 0,
            'game_points' => 0,
            'time_spent_ms' => 0,
            'participant' => true,
            'final_rank' => null,
        ];
    }
}
