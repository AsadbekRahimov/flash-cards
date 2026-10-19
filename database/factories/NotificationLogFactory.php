<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationLog> */
class NotificationLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'type' => fake()->randomElement(['review_reminder', 'exam_starting', 'streak']),
            'payload' => ['text' => fake()->sentence()],
            'sent_at' => now(),
        ];
    }
}
