<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScorePeriod;
use App\Models\Learner;
use App\Models\LearnerScorePeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerScorePeriod> */
class LearnerScorePeriodFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'period' => ScorePeriod::Week,
            'starts_on' => CarbonImmutable::now((string) config('leagues.timezone'))->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'category' => ScoreCategory::Free,
            'rules_version' => 2,
            'fixed_at' => now(),
        ];
    }

    /** The month that starts on the given 1st (`Y-m-d`). */
    public function month(string $firstDay): static
    {
        return $this->state(fn (): array => ['period' => ScorePeriod::Month, 'starts_on' => $firstDay]);
    }

    public function pro(): static
    {
        return $this->state(fn (): array => ['category' => ScoreCategory::Pro]);
    }
}
