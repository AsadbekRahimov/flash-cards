<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Practice\Enums\Verdict;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BattleAnswer> */
class BattleAnswerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'battle_id' => Battle::factory(),
            'learner_id' => Learner::factory(),
            'round' => 1,
            'request_id' => (string) Str::uuid(),
            'payload' => ['text' => 'answer'],
            'verdict' => Verdict::Correct,
            'points' => 100,
            'accepted_at' => now(),
        ];
    }
}
