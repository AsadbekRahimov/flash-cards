<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WordTranslation> */
class WordTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'word_id' => Word::factory(),
            'locale' => 'ru',
            'translation' => fake()->word(),
            'example_translation' => null,
            'source' => 'import',
            'reviewed_at' => null,
        ];
    }

    public function uz(): static
    {
        return $this->state(fn (): array => ['locale' => 'uz']);
    }
}
