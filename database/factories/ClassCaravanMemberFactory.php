<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ClassCaravan;
use App\Models\ClassCaravanMember;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassCaravanMember> */
class ClassCaravanMemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'class_caravan_id' => ClassCaravan::factory(),
            'learner_id' => Learner::factory(),
            'runs' => 0,
            'stars' => 0,
            'best_score' => 0,
            'boss_won_at' => null,
            'last_run_at' => null,
        ];
    }
}
