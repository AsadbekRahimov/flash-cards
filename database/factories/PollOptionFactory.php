<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PollOption> */
class PollOptionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1_000_000);

        return [
            'poll_id' => Poll::factory(),
            'position' => 0,
            'label' => ['ru' => 'Вариант '.$number, 'uz' => 'Variant '.$number],
        ];
    }
}
