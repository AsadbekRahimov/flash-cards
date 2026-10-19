<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TelegramGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TrainingSession> */
class TrainingSessionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory(),
            'lesson_id' => Lesson::factory(),
            'started_by_user_id' => User::factory(),
            'learner_id' => null,
            'mode' => 'group_lesson',
            'source' => 'command',
            'status' => 'open',
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    /** Personal session of one learner (no group / teacher). */
    public function personal(Learner $learner, string $mode = 'lesson'): static
    {
        return $this->state(fn (): array => [
            'telegram_group_id' => null,
            'started_by_user_id' => null,
            'learner_id' => $learner->id,
            'mode' => $mode,
            'source' => 'self',
            'lesson_id' => $mode === 'daily_review' ? null : Lesson::factory(),
            'expires_at' => now()->addHours(12),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'closed',
            'ended_at' => now(),
        ]);
    }
}
