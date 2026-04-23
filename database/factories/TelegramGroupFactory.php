<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TelegramGroup> */
class TelegramGroupFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'chat_id' => fake()->unique()->numberBetween(-1_999_999_999, -1_000_000_000),
            'title' => fake()->company().' English Group',
            'status' => 'active',
            'meta' => ['member_count' => fake()->numberBetween(5, 50)],
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => 'pending']);
    }
}
