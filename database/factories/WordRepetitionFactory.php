<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\RepetitionState;
use App\Models\Learner;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WordRepetition> */
class WordRepetitionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $quality = fake()->numberBetween(0, 5);
        $reps = $quality >= 3 ? fake()->numberBetween(1, 6) : 0;
        $interval = $reps === 0 ? 0 : fake()->numberBetween(1, 30);

        return [
            'learner_id' => Learner::factory(),
            'word_id' => Word::factory(),
            'repetitions' => $reps,
            'interval_days' => $interval,
            'easiness_factor' => fake()->randomFloat(2, 1.30, 2.80),
            'last_quality' => $quality,
            'last_reviewed_at' => now()->subDays(fake()->numberBetween(0, 10)),
            'next_review_at' => now()->addDays(fake()->numberBetween(-2, 14)),
            'is_hard' => $quality <= 2,
            'introduced_mode' => 'lesson',
            // Derived from the (possibly overridden) repetitions count so that
            // `->create(['repetitions' => 5])` yields a graduated card.
            'state' => fn (array $attributes): string => ((int) ($attributes['repetitions'] ?? 0)) >= 1
                ? RepetitionState::Review->value
                : RepetitionState::New->value,
            'learning_step' => 0,
            'lapses' => 0,
            'served_ahead_at' => null,
            'served_ahead_token' => null,
            'meta' => [],
        ];
    }

    /** Graduated card whose day-based review is overdue. */
    public function due(): static
    {
        return $this->state(fn (): array => [
            'state' => RepetitionState::Review->value,
            'repetitions' => fake()->numberBetween(1, 6),
            'interval_days' => fake()->numberBetween(1, 30),
            'next_review_at' => now()->subHours(fake()->numberBetween(1, 24)),
        ]);
    }

    public function hard(): static
    {
        return $this->state(fn (): array => [
            'is_hard' => true,
            'last_quality' => 1,
        ]);
    }

    /** Graduated card scheduled in days (SM-2). */
    public function review(): static
    {
        return $this->state(fn (): array => [
            'state' => RepetitionState::Review->value,
            'repetitions' => fake()->numberBetween(1, 6),
            'interval_days' => fake()->numberBetween(1, 30),
        ]);
    }

    /** Card inside minute-based learning steps (never graduated). */
    public function learning(int $step = 0): static
    {
        return $this->state(fn (): array => [
            'state' => RepetitionState::Learning->value,
            'learning_step' => $step,
            'repetitions' => 0,
            'interval_days' => 0,
        ]);
    }

    /** Lapsed card inside minute-based relearning steps. */
    public function relearning(int $step = 0): static
    {
        return $this->state(fn (): array => [
            'state' => RepetitionState::Relearning->value,
            'learning_step' => $step,
            'repetitions' => 0,
            'interval_days' => 0,
            'lapses' => 1,
            'is_hard' => true,
            'last_quality' => 2,
        ]);
    }
}
