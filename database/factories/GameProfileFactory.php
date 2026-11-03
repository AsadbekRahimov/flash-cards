<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameProfile;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameProfile> */
class GameProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'equipped' => [],
            'tickets' => 0,
            'tickets_earned_total' => 0,
            'stops_cleared' => 0,
            'cities_cleared' => 0,
            'last_run_at' => null,
        ];
    }

    public function withTickets(int $tickets): static
    {
        return $this->state(fn (): array => ['tickets' => $tickets, 'tickets_earned_total' => $tickets]);
    }
}
