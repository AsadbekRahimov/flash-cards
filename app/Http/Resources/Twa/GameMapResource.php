<?php

declare(strict_types=1);

namespace App\Http\Resources\Twa;

use App\Domain\Game\DTO\GameLimitState;
use App\Domain\Game\DTO\GameMapView;
use Illuminate\Http\Request;

/**
 * GET /api/twa/game/map (docs/18 §12.8, docs/04 A.18). `class_caravan` (G2.9b) is the card of the caravan of the class that is on its way, or `null`; `limits` is the block of `/me.game.limits` (G2.8).
 *
 * @property GameMapView $resource
 *
 * @phpstan-import-type City from GameMapView
 * @phpstan-import-type Daily from GameMapView
 * @phpstan-import-type Me from GameMapView
 * @phpstan-import-type Friend from GameMapView
 * @phpstan-import-type ActiveRun from GameMapView
 * @phpstan-import-type ClassCaravanCard from GameMapView
 * @phpstan-import-type LimitsBlock from GameLimitState
 */
final class GameMapResource extends TwaResource
{
    /**
     * @return array{
     *   course: array{slug: string, title: string},
     *   cities: list<City>,
     *   daily: Daily,
     *   me: Me,
     *   friends: list<Friend>,
     *   active_run: ActiveRun|null,
     *   class_caravan: ClassCaravanCard|null,
     *   limits: LimitsBlock
     * }
     */
    public function toArray(Request $request): array
    {
        $map = $this->resource;

        return [
            'course' => $map->course,
            'cities' => $map->cities,
            'daily' => $map->daily,
            'me' => $map->me,
            'friends' => $map->friends,
            'active_run' => $map->activeRun,
            'class_caravan' => $map->classCaravan,
            'limits' => $map->limits,
        ];
    }
}
