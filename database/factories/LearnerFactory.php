<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
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

    public function onboarded(): static
    {
        return $this->state(fn (): array => [
            'onboarding_state' => 'done',
            'onboarding_completed_at' => now(),
        ]);
    }
}
