<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeagueGroup;
use App\Models\LeagueWeek;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeagueGroup> */
class LeagueGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'league_week_id' => LeagueWeek::factory(),
            'tier' => 0,
            'capacity' => (int) config('leagues.capacity', 30),
            'members_count' => 0,
        ];
    }
}
