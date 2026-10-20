<?php

declare(strict_types=1);

namespace Tests\Support;

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

        if (config('cache.default') === 'redis' || config('queue.default') === 'redis' || config('session.driver') === 'redis') {
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
        }
    }
}
