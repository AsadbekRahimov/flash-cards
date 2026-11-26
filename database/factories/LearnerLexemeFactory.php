<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\Lexeme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LearnerLexeme> */
class LearnerLexemeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'lexeme_id' => Lexeme::factory(),
            'confirmed_on' => null,
            'confirmed_source' => null,
            'last_attempt_on' => null,
            'last_credit_due_at' => null,
            'first_long_on' => null,
            'long_free_on' => null,
            'long_pro_on' => null,
        ];
    }

    /** The new word was confirmed on the given rating day (`Y-m-d`). */
    public function confirmedOn(string $day, string $source = 'training'): static
    {
        return $this->state(fn (): array => ['confirmed_on' => $day, 'confirmed_source' => $source]);
    }
}
