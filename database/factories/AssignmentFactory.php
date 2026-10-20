<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'telegram_group_id' => fn (array $attrs) => TelegramGroup::factory()->create(['organization_id' => $attrs['organization_id']])->id,
            'lesson_id' => Lesson::factory(),
            'title' => null,
            'due_at' => now()->addDays(3),
            'announced_at' => null,
            'reminded_at' => null,
            'status' => Assignment::STATUS_ACTIVE,
            'completion_threshold' => 100,
            'created_by_user_id' => null,
            'meta' => [],
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['status' => Assignment::STATUS_CLOSED]);
    }

    public function announced(): static
    {
        return $this->state(fn (): array => ['announced_at' => now()]);
    }
}
