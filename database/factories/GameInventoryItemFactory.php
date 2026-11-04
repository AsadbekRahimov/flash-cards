<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\InventorySource;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\Learner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameInventoryItem> */
class GameInventoryItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'learner_id' => Learner::factory(),
            'item_id' => GameItem::factory(),
            'qty' => 1,
            'acquired_at' => now(),
            'source' => InventorySource::Purchase,
        ];
    }
}
