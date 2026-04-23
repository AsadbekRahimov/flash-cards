<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExamSession;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamSession> */
class ExamSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory(),
            'lesson_id' => Lesson::factory(),
            'started_by_user_id' => User::factory(),
            'status' => 'open',
            'started_at' => now(),
            'ends_at' => now()->addHour(),
            'closed_at' => null,
            'config' => ['questions' => 10],
        ];
    }
}
