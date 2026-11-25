<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lexeme;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lexeme> */
class LexemeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'language' => 'en',
            'key' => fake()->unique()->lexify('????????'),
        ];
    }
}
