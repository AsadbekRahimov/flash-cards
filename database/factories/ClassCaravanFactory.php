<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\ClassCaravanStatus;
use App\Models\ClassCaravan;
use App\Models\Course;
use App\Models\TelegramGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A caravan of a class as a row (docs/18 §12.2.8): by default an ACTIVE week with the minimal target (12 stars).
 *
 * @extends Factory<ClassCaravan>
 */
class ClassCaravanFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'telegram_group_id' => TelegramGroup::factory(),
            'organization_id' => null,
            'course_id' => Course::factory()->published(),
            'started_by_user_id' => null,
            'active_group_id' => fn (array $attributes): ?int => $attributes['status'] === ClassCaravanStatus::Active ? $attributes['telegram_group_id'] : null,
            'status' => ClassCaravanStatus::Active,
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'target_stars' => 12,
            'stars_total' => 0,
            'runs_total' => 0,
            'boss_snapshot_words' => null,
            'boss_unlocked_at' => null,
            'boss_defeated_at' => null,
            'summary_posted_at' => null,
            'finished_at' => null,
            'meta' => [],
        ];
    }

    public function finished(): static
    {
        return $this->state(fn (): array => [
            'status' => ClassCaravanStatus::Finished,
            'active_group_id' => null,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (): array => ['status' => ClassCaravanStatus::Canceled, 'active_group_id' => null, 'finished_at' => now()]);
    }
}
