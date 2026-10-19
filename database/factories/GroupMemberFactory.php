<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupMember> */
class GroupMemberFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory(),
            'learner_id' => Learner::factory(),
            'status' => 'active',
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(fn (): array => [
            'status' => 'left',
            'left_at' => now(),
        ]);
    }
}
