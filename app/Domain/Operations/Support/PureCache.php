<?php

declare(strict_types=1);

namespace App\Domain\Operations\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The one door to the PURE cache (docs/19_REDIS_CACHE_PLAN.md §3 rule 10, §4): the store on the second, evicting
 * Redis instance (`config('cache.pure_store')`, `lru` in production, `array` in tests). Anything written here may vanish
 * at any moment and must be recomputable from the database: catalog listings, standings, aggregates of widgets.
 *
 * What does NOT belong here, ever: markers ("already sent", "paywall shown"), locks, counters, rate limits, the 2FA
 * attempt protection, the entitlement version keys. Those stay on the default store (`Cache::`), whose instance never
 * evicts. Locks taken on this store (`Cache::lock`, `Cache::flexible`) are placed on the non-evicting instance by the
 * store's `lock_connection`.
 */
final class PureCache
{
    /** Container key of the pure store (bound in AppServiceProvider); a test swaps it with `app()->instance()`. */
    public const CONTAINER_KEY = 'cache.pure';

    public static function store(): Repository
    {
        /** @var Repository */
        return app(self::CONTAINER_KEY);
    }

    /** What the container binding resolves: the configured store of the cache manager. */
    public static function resolve(): Repository
    {
        return Cache::store(self::storeName());
    }

    /** The configured store name, for readiness and tests. */
    public static function storeName(): string
    {
        return (string) config('cache.pure_store', 'lru');
    }

    /** The Redis connection behind the pure store, or null when it is not a Redis store (tests: `array`). */
    public static function redisConnection(): ?string
    {
        $store = self::storeName();
        if (config("cache.stores.{$store}.driver") !== 'redis') {
            return null;
        }

        return (string) config("cache.stores.{$store}.connection", 'default');
    }
}
