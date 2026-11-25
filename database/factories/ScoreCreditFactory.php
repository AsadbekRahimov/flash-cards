<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Models\Learner;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScoreCredit> */
class ScoreCreditFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'rating_day' => CarbonImmutable::now((string) config('leagues.timezone'))->toDateString(),
            'lexeme_id' => Lexeme::factory(),
            'word_id' => fake()->numberBetween(1, 1_000_000),
            'source' => ScoreSource::Training,
            'source_id' => fake()->numberBetween(1, 1_000_000),
            'kind' => ScoreKind::New,
            'week_points' => 2,
            'month_points' => 2,
        ];
    }
}
