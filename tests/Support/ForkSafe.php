<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Operations\Support\PureCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * pcntl_fork() duplicates open sockets: a connection shared by the parent and
 * its children interleaves replies (phpredis: "socket error on read socket",
 * "unserialize(): Error at offset 0"). Call before forking and in each child.
 */
final class ForkSafe
{
    public static function releaseConnections(): void
    {
        DB::disconnect();

        $pureOnRedis = PureCache::redisConnection() !== null;

        if (config('cache.default') === 'redis' || $pureOnRedis || config('queue.default') === 'redis' || config('session.driver') === 'redis') {
            // Every connection of config, the second instance (`lru`) included.
            foreach (array_keys((array) config('database.redis', [])) as $name) {
                if (in_array($name, ['client', 'options', 'clusters'], true)) {
                    continue;
                }
                try {
                    Redis::connection((string) $name)->client()->close();
                } catch (\Throwable) {
                    // not connected / not phpredis
                }
            }
            app('redis')->purge();
            Cache::forgetDriver();
            // The repositories of every Redis-backed store (and of the failover chain over one) hold the closed clients.
            foreach ((array) config('cache.stores', []) as $name => $store) {
                if (in_array($store['driver'] ?? null, ['redis', 'failover'], true)) {
                    Cache::forgetDriver((string) $name);
                }
            }
        }
    }
}
