<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameBoosterUse;
use App\Models\GameItem;
use App\Models\GameRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameBoosterUse> */
class GameBoosterUseFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => fn (array $attributes): int => (int) GameRun::query()->whereKey($attributes['run_id'])->value('learner_id'),
            'run_id' => GameRun::factory(),
            'item_id' => GameItem::factory()->booster(),
            'element_id' => null,
            'used_at' => now(),
            'week_start' => now()->startOfWeek()->toDateString(),
        ];
    }
}
