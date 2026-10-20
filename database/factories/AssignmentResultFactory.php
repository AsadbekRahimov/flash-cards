<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AssignmentResult> */
class AssignmentResultFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'learner_id' => Learner::factory(),
            'progress_pct' => 0,
            'completed_at' => null,
            'computed_at' => now(),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['progress_pct' => 100, 'completed_at' => now()]);
    }
}
