<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\LeagueMonthStatus;
use App\Models\LeagueMonth;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeagueMonth> */
class LeagueMonthFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $first = CarbonImmutable::now((string) config('leagues.timezone'))->startOfMonth();

        return [
            'starts_on' => $first->toDateString(),
            'ends_on' => $first->endOfMonth()->toDateString(),
            'status' => LeagueMonthStatus::Open->value,
            'prizes' => null,
            'participants_count' => 0,
        ];
    }

    /** The month that starts on the given first day (`Y-m-01`). */
    public function startingOn(string $firstDay): static
    {
        $first = CarbonImmutable::parse($firstDay)->startOfMonth();

        return $this->state(fn (): array => [
            'starts_on' => $first->toDateString(),
            'ends_on' => $first->endOfMonth()->toDateString(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => ['status' => LeagueMonthStatus::PendingReview->value, 'closed_at' => now()]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => LeagueMonthStatus::Confirmed->value,
            'closed_at' => now(),
            'confirmed_at' => now(),
        ]);
    }
}
