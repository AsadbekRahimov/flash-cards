<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GameStopProgress;
use App\Models\Learner;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<GameStopProgress> */
class GameStopProgressFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'lesson_id' => Lesson::factory(),
            'course_id' => fn (array $attributes): int => (int) DB::table('lessons')->join('stages', 'stages.id', '=', 'lessons.stage_id')->where('lessons.id', $attributes['lesson_id'])->value('stages.course_id'),
            'stage_id' => fn (array $attributes): int => (int) Lesson::query()->whereKey($attributes['lesson_id'])->value('stage_id'),
            'stars' => 0,
            'best_score' => 0,
            'runs_count' => 0,
            'first_cleared_at' => null,
            'last_run_at' => now(),
        ];
    }

    public function cleared(int $stars = 3, int $score = 120): static
    {
        return $this->state(fn (): array => ['stars' => $stars, 'best_score' => $score, 'runs_count' => 1, 'first_cleared_at' => now()]);
    }
}
