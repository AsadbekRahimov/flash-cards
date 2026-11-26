<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ScoreCategory;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerScoreDay> */
class LearnerScoreDayFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $day = CarbonImmutable::now((string) config('leagues.timezone'))->startOfDay();

        return [
            'learner_id' => Learner::factory(),
            'rating_day' => $day->toDateString(),
            'week_starts_on' => $day->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'week_category' => ScoreCategory::Free,
            'week_version' => 2,
            'month_starts_on' => $day->startOfMonth()->toDateString(),
            'month_category' => ScoreCategory::Free,
            'month_version' => 2,
        ];
    }

    /** The row of the given rating day (`Y-m-d`), with its week and month. */
    public function on(string $day): static
    {
        $date = CarbonImmutable::parse($day);

        return $this->state(fn (): array => [
            'rating_day' => $date->toDateString(),
            'week_starts_on' => $date->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
            'month_starts_on' => $date->startOfMonth()->toDateString(),
        ]);
    }
}
