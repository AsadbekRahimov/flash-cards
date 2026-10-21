<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Engagement\Enums\LeagueWeekStatus;
use App\Models\LeagueWeek;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeagueWeek> */
class LeagueWeekFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $monday = CarbonImmutable::now((string) config('leagues.timezone'))->startOfWeek(CarbonInterface::MONDAY);

        return [
            'starts_on' => $monday->toDateString(),
            'ends_on' => $monday->addDays(6)->toDateString(),
            'status' => LeagueWeekStatus::Open->value,
            'closed_at' => null,
        ];
    }

    /** The week that starts on the given Monday (`Y-m-d`). */
    public function startingOn(string $monday): static
    {
        return $this->state(fn (): array => [
            'starts_on' => $monday,
            'ends_on' => CarbonImmutable::parse($monday)->addDays(6)->toDateString(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => LeagueWeekStatus::Closed->value,
            'closed_at' => now(),
        ]);
    }
}
