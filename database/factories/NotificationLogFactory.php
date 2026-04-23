<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationLog;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationLog> */
class NotificationLogFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => fake()->randomElement(['review_reminder', 'exam_starting', 'streak']),
            'payload' => ['text' => fake()->sentence()],
            'sent_at' => now(),
        ];
    }
}
