<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\BattleRole;
use App\Models\Battle;
use App\Models\BattleParticipant;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BattleParticipant> */
class BattleParticipantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'battle_id' => Battle::factory(),
            'learner_id' => Learner::factory(),
            'role' => BattleRole::Challenger,
            'active_learner_id' => null,
            'ready_at' => null,
            'last_seen_at' => null,
            'surrendered_at' => null,
            'score' => 0,
            'locale' => 'ru',
        ];
    }
}
