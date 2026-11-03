<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\AttemptKind;
use App\Domain\Practice\Enums\Verdict;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GameRunAttempt> */
class GameRunAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'run_id' => GameRun::factory(),
            'learner_id' => fn (array $attributes): int => (int) GameRun::query()->whereKey($attributes['run_id'])->value('learner_id'),
            'request_id' => (string) Str::uuid(),
            'element_id' => 'e'.Str::random(10),
            'kind' => AttemptKind::Answer,
            'payload' => ['option_id' => 'o'.Str::random(6)],
            'verdict' => Verdict::Correct,
            'is_first' => true,
            'result' => ['verdict' => 'correct'],
            'time_spent_ms' => null,
            'accepted_at' => now(),
        ];
    }
}
