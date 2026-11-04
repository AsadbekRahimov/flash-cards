<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\ProfileView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/profile and the answer of POST /game/profile/equip (docs/18 §12.8, docs/04 A.18). `equipped` is an object even when empty.
 *
 * @property ProfileView $resource
 *
 * @phpstan-import-type Worn from ProfileView
 */
final class GameProfileResource extends TwaResource
{
    /**
     * @return array{equipped: object|array<string, Worn>, stops_cleared: int, cities_cleared: int, coins: int, tickets: int}
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;

        return [
            'equipped' => $profile->equipped === [] ? (object) [] : $profile->equipped,
            'stops_cleared' => $profile->stopsCleared,
            'cities_cleared' => $profile->citiesCleared,
            'coins' => $profile->coins,
            'tickets' => $profile->tickets,
        ];
    }
}
