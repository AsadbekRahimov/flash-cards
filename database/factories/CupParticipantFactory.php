<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Cup\Enums\CupParticipantStatus;
use App\Models\Cup;
use App\Models\CupParticipant;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CupParticipant> */
class CupParticipantFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cup_id' => Cup::factory(),
            'learner_id' => Learner::factory(),
            'display_name' => fake()->firstName(),
            'status' => CupParticipantStatus::Active,
            'joined_at' => now(),
            'first_day' => 1,
            'membership_checked_at' => now(),
        ];
    }
}
