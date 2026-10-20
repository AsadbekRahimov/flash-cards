<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\ChallengeMetric;
use App\Domain\Engagement\Enums\ChallengeStatus;
use App\Models\Challenge;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Challenge> */
class ChallengeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'challenger_id' => Learner::factory(),
            'opponent_id' => Learner::factory(),
            'metric' => ChallengeMetric::Reviews->value,
            'days' => 7,
            'status' => ChallengeStatus::Pending->value,
        ];
    }

    /** Running over the local dates `$startsOn`..`ends_on` (`days` long), ending at `$endsAt`. */
    public function active(string $startsOn = '2026-10-23', ?\DateTimeInterface $endsAt = null): static
    {
        return $this->state(function (array $attributes) use ($startsOn, $endsAt): array {
            $days = is_int($attributes['days'] ?? null) ? $attributes['days'] : 7;
            $endsOn = CarbonImmutable::parse($startsOn)->addDays($days - 1);

            return [
                'status' => ChallengeStatus::Active->value,
                'starts_at' => now(),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn->toDateString(),
                'ends_at' => $endsAt ?? $endsOn->addDay(),
            ];
        });
    }
}
