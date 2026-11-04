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
 * `throttle:*` in ONE Redis command per request (docs/19 §5.1, K9 / R12i).
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
 * One script serves all limits of the request (variant (a) of R12j). It touches two or more keys, which in Redis Cluster
 * is CROSSSLOT unless the keys share a hash slot; the limiter store is a single instance now, revisit if it is clustered.
 *
 * A refused request does not count (as in the original); `Retry-After` is the rest of the window as the timer says. When
 * the limiter store is not Redis with phpredis (the tests on sqlite run on `array`) every call goes to the parent.
 */
final class AtomicThrottle extends ThrottleRequests
{
    /**
     * KEYS: pairs (counter, its timer), one pair per limit. ARGV[1] now (unix seconds); then three values per limit:
     * max attempts, decay seconds, the end of a window that starts now. Like the parent (`tooManyAttempts` for all limits,
     * then `hit` for all) it first checks every limit and only if none is exhausted hits them all, so a refusal by the
     * second limit does not spend the first one. Returns {0, index of the refused limit, seconds until its window ends}
     * or {1, hits after this call of limit 1, of limit 2, ...}.
     */
    private const SCRIPT = <<<'LUA'
local n = #KEYS / 2
local now = tonumber(ARGV[1])
for i = 1, n do
    local counter, timerKey = KEYS[2 * i - 1], KEYS[2 * i]
    local hits = tonumber(redis.call('GET', counter) or '0')
    if hits >= tonumber(ARGV[3 * i - 1]) then
        local timer = redis.call('GET', timerKey)
        if timer then
            return {0, i, math.max(0, tonumber(timer) - now)}
        end
        redis.call('DEL', counter)
    end
end
local result = {1}
for i = 1, n do
    local counter, timerKey = KEYS[2 * i - 1], KEYS[2 * i]
    local decay, window = ARGV[3 * i], ARGV[3 * i + 1]
    redis.call('SET', timerKey, window, 'EX', decay, 'NX')
    local added = redis.call('SET', counter, '0', 'EX', decay, 'NX')
    local hits = redis.call('INCRBY', counter, 1)
    if (not added) and hits == 1 then
        redis.call('SET', counter, '1', 'EX', decay)
    end
    result[#result + 1] = hits
end
return result
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

        $keys = [];
        $args = [$now];
        foreach ($limits as $limit) {
            $key = $this->limiter->cleanRateLimiterKey($limit->key);
            $decay = max(1, (int) $limit->decaySeconds);
            array_push($keys, $prefix.$key, $prefix.$key.':timer');
            array_push($args, (int) $limit->maxAttempts, $decay, $now + $decay);
        }

        /** @var array<int, int> $result */
        $result = $connection->eval(self::SCRIPT, count($keys), ...$keys, ...$args);

        if ((int) $result[0] !== 1) {
            $refused = $limits[(int) $result[1] - 1];
            $this->retryAfter[$refused->key] = (int) $result[2];

            throw $this->buildException($request, $refused->key, $refused->maxAttempts, $refused->responseCallback);
        }

        foreach ($limits as $i => $limit) {
            $this->remaining[$limit->key] = max(0, (int) $limit->maxAttempts - (int) $result[$i + 1]);
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
