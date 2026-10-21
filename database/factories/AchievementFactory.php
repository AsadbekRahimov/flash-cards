<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\AchievementKey;
use App\Models\Achievement;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Achievement> */
class AchievementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'key' => AchievementKey::Streak7->value,
            'unlocked_at' => now(),
            'shared_at' => null,
            'meta' => null,
        ];
    }

    public function key(AchievementKey $key): static
    {
        return $this->state(fn (): array => ['key' => $key->value]);
    }
}
