<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['import.uploaded', 'group.activated', 'lesson.created']),
            'entity_type' => null,
            'entity_id' => null,
            'ip' => fake()->ipv4(),
            'meta' => [],
            'created_at' => now(),
        ];
    }
}
