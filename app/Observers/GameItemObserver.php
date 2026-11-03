<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Game\Services\GameShopCache;
use App\Models\GameItem;
use Illuminate\Support\Facades\DB;

/**
 * The cached catalogue of the shop (docs/18 §12.12) is made of `game_items`: whatever path saves or deletes an item (the admin panel,
 * the seeder, a console command) moves the version of the catalogue on after the commit. Registered in AppServiceProvider; query-builder
 * writes bypass model events and must call {@see GameShopCache::bump()} themselves.
 */
final class GameItemObserver
{
    public function saved(GameItem $item): void
    {
        DB::afterCommit(GameShopCache::bump(...));
    }

    public function deleted(GameItem $item): void
    {
        DB::afterCommit(GameShopCache::bump(...));
    }
}
