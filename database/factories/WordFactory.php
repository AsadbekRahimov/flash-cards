<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Word> */
class WordFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return [
            'lesson_id' => Lesson::factory(),
            'word' => $word,
            'translation' => fake()->word(),
            'example' => fake()->sentence(),
            'part_of_speech' => fake()->randomElement(['noun', 'verb', 'adjective', 'adverb']),
            'transcription' => '/'.$word.'/',
            'meta' => [],
        ];
    }
}
