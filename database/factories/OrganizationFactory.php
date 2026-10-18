<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Learning Center',
            'slug' => fake()->unique()->slug(3),
            'type' => 'center',
            'status' => 'active',
            'timezone' => 'Asia/Tashkent',
            'locale' => 'ru',
            'settings' => [],
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => 'suspended']);
    }
}
