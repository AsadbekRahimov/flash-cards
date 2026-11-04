<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Enums\ItemAvailability;
use App\Domain\Game\Enums\ItemKind;
use App\Domain\Game\Enums\ItemSlot;
use App\Domain\Game\Enums\ItemStatus;
use App\Models\GameItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An item of the catalogue (docs/18 §12.2.6): by default a published cosmetic of the slot `camel` for sale. The shape
 * "a cosmetic has a slot, a booster has an effect" is kept by {@see cosmetic()} and {@see booster()}.
 *
 * @extends Factory<GameItem>
 */
class GameItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'key' => "item_{$counter}",
            'kind' => ItemKind::Cosmetic,
            'slot' => ItemSlot::Camel,
            'effect' => null,
            'price_coins' => 50,
            'availability' => ItemAvailability::Base,
            'achievement_key' => null,
            'season_id' => null,
            'status' => ItemStatus::Published,
            'sort' => $counter,
            'title' => ['ru' => "Предмет {$counter}", 'uz' => "Buyum {$counter}"],
            'description' => null,
            'asset' => null,
        ];
    }

    public function cosmetic(ItemSlot $slot = ItemSlot::Camel): static
    {
        return $this->state(fn (): array => ['kind' => ItemKind::Cosmetic, 'slot' => $slot, 'effect' => null]);
    }

    public function booster(BoosterEffect $effect = BoosterEffect::Hint): static
    {
        return $this->state(fn (): array => ['kind' => ItemKind::Booster, 'slot' => null, 'effect' => $effect, 'price_coins' => 20]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => ItemStatus::Draft]);
    }

    public function exclusive(): static
    {
        return $this->state(fn (): array => ['availability' => ItemAvailability::Exclusive]);
    }
}
