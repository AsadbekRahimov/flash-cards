<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\ShopView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/shop (docs/18 §12.8, docs/04 A.18).
 *
 * @property ShopView $resource
 *
 * @phpstan-import-type ShopItem from ShopView
 */
final class GameShopResource extends TwaResource
{
    /**
     * @return array{items: list<ShopItem>, balance: int}
     */
    public function toArray(Request $request): array
    {
        return ['items' => $this->resource->items, 'balance' => $this->resource->balance];
    }
}
