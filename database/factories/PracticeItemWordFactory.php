<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PracticeItem;
use App\Models\PracticeItemWord;
use App\Models\Word;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PracticeItemWord> */
class PracticeItemWordFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'item_id' => PracticeItem::factory()->bare(),
            'word_id' => Word::factory(),
        ];
    }
}
