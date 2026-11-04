<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameWordMiss;
use App\Models\Learner;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameWordMiss> */
class GameWordMissFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'word_id' => Word::factory(),
            'misses' => 1,
            'last_missed_at' => now(),
            'last_pulled_at' => null,
        ];
    }
}
