<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameCityProgress;
use App\Models\Learner;
use App\Models\Stage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameCityProgress> */
class GameCityProgressFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'stage_id' => Stage::factory(),
            'course_id' => fn (array $attributes): int => (int) Stage::query()->whereKey($attributes['stage_id'])->value('course_id'),
            'boss_attempts' => 0,
            'boss_defeated_at' => null,
            'boss_best_accuracy' => 0,
            'boss_wins' => 0,
            'shared_at' => null,
        ];
    }

    public function defeated(int $accuracy = 92): static
    {
        return $this->state(fn (): array => ['boss_attempts' => 1, 'boss_wins' => 1, 'boss_best_accuracy' => $accuracy, 'boss_defeated_at' => now()]);
    }
}
