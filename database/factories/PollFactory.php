<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Polls\Enums\PollStatus;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Poll> */
class PollFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => 'Опрос '.fake()->unique()->numberBetween(1, 1_000_000),
            'question' => ['ru' => 'Какой формат занятий вам удобнее?', 'uz' => 'Qaysi dars formati sizga qulayroq?'],
            'allows_multiple' => false,
            'max_choices' => null,
            'allow_change' => true,
            'audience' => ['plan' => 'all', 'locales' => [], 'course_ids' => [], 'region_codes' => [], 'onboarded_only' => true],
            'status' => PollStatus::Draft,
        ];
    }

    /** Launched: the broadcast is running. */
    public function sending(): static
    {
        return $this->state(fn (): array => ['status' => PollStatus::Sending, 'launched_at' => now()]);
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => PollStatus::Closed,
            'launched_at' => now()->subDay(),
            'closed_at' => now(),
            'results_snapshot' => [],
        ]);
    }

    /** Several answers, at most `$maxChoices` of them (null = any number). */
    public function multiple(?int $maxChoices = null): static
    {
        return $this->state(fn (): array => ['allows_multiple' => true, 'max_choices' => $maxChoices]);
    }

    /** With `$count` options at positions 0.. ("Вариант 1", "Variant 1", …). */
    public function withOptions(int $count = 2): static
    {
        return $this->afterCreating(function (Poll $poll) use ($count): void {
            for ($position = 0; $position < $count; $position++) {
                PollOption::factory()->create(['poll_id' => $poll->id, 'position' => $position]);
            }
        });
    }
}
