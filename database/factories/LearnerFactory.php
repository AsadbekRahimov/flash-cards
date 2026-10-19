<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Learner> */
class LearnerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->optional()->lastName(),
            'username' => fake()->optional()->userName(),
            'language_code' => fake()->randomElement(['en', 'ru', 'uz']),
            'interface_locale' => 'ru',
            'timezone' => (string) config('learners.default_timezone'),
            'daily_goal' => (int) config('learners.default_daily_goal'),
            'reminder_time' => (string) config('learners.default_reminder_time'),
            'onboarding_state' => 'new',
            'meta' => [],
        ];
    }

    /** The learner is (by default an active) member of $group. */
    public function memberOf(TelegramGroup|int $group, string $status = 'active'): static
    {
        $groupId = $group instanceof TelegramGroup ? $group->id : $group;

        return $this->afterCreating(function (Learner $learner) use ($groupId, $status): void {
            GroupMember::factory()->create([
                'telegram_group_id' => $groupId,
                'learner_id' => $learner->id,
                'status' => $status,
                'left_at' => $status === 'active' ? null : now(),
            ]);
        });
    }

    public function onboarded(): static
    {
        return $this->state(fn (): array => [
            'onboarding_state' => 'done',
            'onboarding_completed_at' => now(),
        ]);
    }
}
