<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Stand-ins for the second Redis instance behind the pure cache (docs/19 §4.1) and the plumbing to put the production
 * chain `lru` in front of them: a closed port (refused at once), a socket that accepts and never answers (hung: every
 * read waits for the timeout), or the live instance of the environment.
 */
final class PureCacheStandIn
{
    /** Nothing listens here. */
    public const DEAD_PORT = 56399;

    /**
     * A server that completes the TCP handshake (the kernel does, from the backlog) and never accepts nor answers: what
     * a paused container, a swapped-out or a blocked redis-server looks like to a client. Keep the resource alive.
     *
     * @return resource
     */
    public static function hungServer()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("cannot listen on 127.0.0.1: {$errstr}");
        }

        return $server;
    }

    /**
     * @param  resource  $server
     */
    public static function portOf($server): int
    {
        $name = (string) stream_socket_get_name($server, false);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * How many connections reached the hung server since the last call: it accepts them now, one by one. A connection the
     * client has already closed is still delivered by accept(), so every attempt counts.
     *
     * @param  resource  $server
     */
    public static function connectionsReceived($server): int
    {
        $count = 0;

        while (true) {
            $read = [$server];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0) !== 1) {
                return $count;
            }
            $connection = stream_socket_accept($server, 0);
            if ($connection === false) {
                return $count;
            }
            fclose($connection);
            $count++;
        }
    }

    /** A directory of its own for the markers of the circuit breaker: tests must not share state through the real one. */
    public static function markerDirectory(): string
    {
        $directory = storage_path('framework/testing/circuits-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        return $directory;
    }

    public static function removeDirectory(string $directory): void
    {
        File::deleteDirectory($directory);
    }

    /**
     * The production chain of the pure store (`lru` = `lru_redis` then `array`, behind the circuit breaker) with the `lru`
     * connection pointed at `$host:$port`. The Redis manager copies its config when it is built (the default store may
     * already have built it), so it is rebuilt; the chain's repository is rebuilt too unless `$keepChain` - a long-lived
     * worker that keeps its chain, and with it the memory of its breaker, while its connection is rebuilt.
     */
    public static function pointPureCacheAt(string $host, int $port, string $markerDirectory, int $openSeconds = 15, bool $keepChain = false): void
    {
        Config::set('database.redis.lru', [
            ...(array) config('database.redis.lru'),
            'host' => $host,
            'port' => $port,
            'max_retries' => 0,
        ]);

        $config = (array) config('database.redis');
        $client = (string) ($config['client'] ?? 'phpredis');
        unset($config['client']);
        app()->forgetInstance('redis');
        app()->instance('redis', new RedisManager(app(), $client, $config));

        Config::set('cache.stores.lru.marker_path', $markerDirectory);
        Config::set('cache.stores.lru.open_seconds', $openSeconds);
        Config::set('cache.pure_store', 'lru');

        Cache::forgetDriver('lru_redis');
        if (! $keepChain) {
            Cache::forgetDriver('lru');
        }
    }

    /**
     * The live second instance of the ENVIRONMENT (CI, the PostgreSQL run: `CACHE_PURE_STORE=lru` and `REDIS_LRU_*` in
     * the environment), or null when the suite runs the pure store on the array store. Read from the environment, not from
     * the config, which a test may already have pointed at a stand-in.
     *
     * @return array{host: string, port: int}|null
     */
    public static function liveInstance(): ?array
    {
        if (! extension_loaded('redis') || env('CACHE_PURE_STORE') !== 'lru') {
            return null;
        }

        $host = (string) env('REDIS_LRU_HOST', '127.0.0.1');
        $port = (int) env('REDIS_LRU_PORT', 6380);
        $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($socket === false) {
            return null;
        }
        fclose($socket);

        return ['host' => $host, 'port' => $port];
    }
}
