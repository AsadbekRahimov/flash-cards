<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_user_id' => fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'telegram_group_id' => TelegramGroup::factory(),
            'username' => fake()->optional()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->optional()->lastName(),
            'language_code' => fake()->randomElement(['en', 'ru', 'uz']),
            'last_seen_at' => fake()->optional()->dateTimeBetween('-30 days'),
            'is_active' => true,
        ];
    }
}
