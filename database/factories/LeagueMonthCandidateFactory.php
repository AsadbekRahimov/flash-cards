<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeagueMonthCandidate> */
class LeagueMonthCandidateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'league_month_id' => LeagueMonth::factory(),
            'learner_id' => Learner::factory(),
            'rank' => 1,
            'points' => 100,
            'active_days' => 15,
            'signals' => [],
            'status' => LeagueMonthCandidateStatus::Candidate->value,
            'place' => null,
        ];
    }

    public function winner(int $place): static
    {
        return $this->state(fn (): array => ['status' => LeagueMonthCandidateStatus::Winner->value, 'place' => $place]);
    }

    public function disqualified(string $reason = 'script'): static
    {
        return $this->state(fn (): array => [
            'status' => LeagueMonthCandidateStatus::Disqualified->value,
            'disqualified_at' => now(),
            'disqualify_reason' => $reason,
        ]);
    }
}
