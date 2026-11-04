<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;

/**
 * `throttle:*` in ONE Redis command per limiter (docs/19 §5.1, K9 / R12i).
 *
 * Laravel's ThrottleRequests spends five commands of the limiter store on every allowed request: `GET` (the attempts),
 * then the hit - `SET NX` of the timer, `SET NX` of the counter, `INCRBY` - and one more `GET` for the `X-RateLimit-Remaining`
 * header. A TWA request carries one or two limiters, so 5–10 commands went to Redis before the first query of the request.
 * Here the check, the hit and the remaining count are one Lua script: the same keys, the same values and the same window
 * as {@see RateLimiter} (`<key>` = the plain counter, `<key>:timer` = the end of the window, both with the decay as TTL,
 * the window counted from the first hit), so `RateLimiter::hit()` / `attempts()` / `availableIn()` / `clear()` keep
 * reading and writing the same thing - the tests exhaust a limiter with `RateLimiter::hit()` and the login / 2FA
 * limiters, which use the RateLimiter directly, are not touched.
 *
 * A refused request does not count (as in the original); `Retry-After` is the rest of the window as the timer says. When
 * the limiter store is not Redis with phpredis (the tests on sqlite run on `array`) every call goes to the parent.
 */
final class AtomicThrottle extends ThrottleRequests
{
    /**
     * KEYS[1] the counter, KEYS[2] its timer; ARGV[1] max attempts, ARGV[2] decay seconds, ARGV[3] now (unix seconds),
     * ARGV[4] the end of a window that starts now. Returns {allowed 0|1, hits after this call, seconds until the window ends
     * (0 when allowed)}.
     */
    private const SCRIPT = <<<'LUA'
local hits = tonumber(redis.call('GET', KEYS[1]) or '0')
if hits >= tonumber(ARGV[1]) then
    local timer = redis.call('GET', KEYS[2])
    if timer then
        return {0, hits, math.max(0, tonumber(timer) - tonumber(ARGV[3]))}
    end
    redis.call('DEL', KEYS[1])
    hits = 0
end
redis.call('SET', KEYS[2], ARGV[4], 'EX', ARGV[2], 'NX')
local added = redis.call('SET', KEYS[1], '0', 'EX', ARGV[2], 'NX')
hits = redis.call('INCRBY', KEYS[1], 1)
if (not added) and hits == 1 then
    redis.call('SET', KEYS[1], '1', 'EX', ARGV[2])
end
return {1, hits, 0}
LUA;

    /** @var array<string, int> attempts left after this request, by limiter key */
    private array $remaining = [];

    /** @var array<string, int> seconds until the window of a refused key ends */
    private array $retryAfter = [];

    private ?RedisStore $redisStore = null;

    private bool $storeResolved = false;

    /**
     * @param  array<int, object{key: string, maxAttempts: int, decaySeconds: int, afterCallback: mixed, responseCallback: mixed}>  $limits
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        $store = $this->redisStore();
        $connection = $store?->connection();
        if ($store === null || ! $connection instanceof PhpRedisConnection || $this->anyAfterCallback($limits)) {
            return parent::handleRequest($request, $next, $limits);
        }

        $prefix = $store->getPrefix();
        $now = $this->currentTime();

        foreach ($limits as $limit) {
            $key = $this->limiter->cleanRateLimiterKey($limit->key);
            $decay = max(1, (int) $limit->decaySeconds);

            /** @var array{0: int, 1: int, 2: int} $result */
            $result = $connection->eval(
                self::SCRIPT, 2, $prefix.$key, $prefix.$key.':timer', (int) $limit->maxAttempts, $decay, $now, $now + $decay,
            );

            if ((int) $result[0] !== 1) {
                $this->retryAfter[$limit->key] = (int) $result[2];

                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }

            $this->remaining[$limit->key] = max(0, (int) $limit->maxAttempts - (int) $result[1]);
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            $response = $this->addHeaders($response, $limit->maxAttempts, $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts));
        }

        return $response;
    }

    protected function calculateRemainingAttempts($key, $maxAttempts, $retryAfter = null)
    {
        if (! array_key_exists($key, $this->remaining) && ! array_key_exists($key, $this->retryAfter)) {
            return parent::calculateRemainingAttempts($key, $maxAttempts, $retryAfter);
        }

        return $retryAfter === null ? ($this->remaining[$key] ?? 0) : 0;
    }

    protected function getTimeUntilNextRetry($key)
    {
        return $this->retryAfter[$key] ?? parent::getTimeUntilNextRetry($key);
    }

    /** The Redis store of the RateLimiter (`cache.limiter`), or null when the limiter is on another kind of store. */
    private function redisStore(): ?RedisStore
    {
        if (! $this->storeResolved) {
            $this->storeResolved = true;
            $store = Cache::store((string) config('cache.limiter', config('cache.default')))->getStore();
            if ($store instanceof RedisStore && $store->connection() instanceof PhpRedisConnection) {
                $this->redisStore = $store;
            }
        }

        return $this->redisStore;
    }

    /** @param  array<int, object{afterCallback: mixed}>  $limits */
    private function anyAfterCallback(array $limits): bool
    {
        foreach ($limits as $limit) {
            if ($limit->afterCallback) {
                return true;
            }
        }

        return false;
    }
}
