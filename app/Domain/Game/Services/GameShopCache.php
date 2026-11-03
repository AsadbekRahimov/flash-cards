<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use Illuminate\Support\Facades\Cache;

/**
 * The keys of `game:shop` (docs/19 §2.2, docs/18 §12.12): the published catalogue of the shop in one locale, a pure cache. The VERSION
 * lives on the non-evicting default store (no TTL) and is in the key of every value; `GameItemObserver` moves it on after the commit of
 * any save / delete of an item, so a reader that built the catalogue from older rows writes to a key nobody reads any more.
 */
final class GameShopCache
{
    /** The version of the FORMAT of the value (raise it when the shape changes: nobody reads the old entries). */
    public const VERSION = 'v1';

    public const TTL_SECONDS = 1800;

    public const VERSION_KEY = 'game:shop:ver';

    public static function key(string $locale): string
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);

        return 'game:shop:'.self::VERSION.":{$locale}:n{$version}";
    }

    /** Whatever was cached is stale from now on. */
    public static function bump(): void
    {
        Cache::increment(self::VERSION_KEY);
    }
}
