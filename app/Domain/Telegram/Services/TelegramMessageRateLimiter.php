<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

final class TelegramMessageRateLimiter
{
    public function __construct(
        private readonly int $maxMessages = 20,
        private readonly int $windowSeconds = 60,
        private readonly int $globalPerSecond = 25,
    ) {}

    /**
     * Bot-wide token bucket for background sends (docs/13 §9, 2R.3): at most `globalPerSecond`
     * slots per wall-clock second, shared by every worker (`telegram:outbound:global:{sec}`, TTL 2 s).
     * True = a slot was taken, send now; false = none left, the caller releases / postpones with
     * {@see self::globalRetryDelay()}. `0` switches the limit off. Replies to an incoming update
     * are not counted here (they never go through this method).
     */
    public function reserveGlobal(): bool
    {
        if ($this->globalPerSecond <= 0) {
            return true;
        }

        $key = 'telegram:outbound:global:'.now()->getTimestamp();

        return $this->incrementWithTtl($key, 2) <= $this->globalPerSecond;
    }

    /**
     * Waits for a global slot, at most `$maxWaitMs`, for synchronous callers (console commands) that
     * cannot `release()`. False = still none: the caller must postpone its work to the next run.
     */
    public function awaitGlobal(int $maxWaitMs = 2000): bool
    {
        $deadline = hrtime(true) + $maxWaitMs * 1_000_000;

        while (! $this->reserveGlobal()) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            // Up to the start of the next second, in short naps.
            usleep(50_000);
        }

        return true;
    }

    /** Seconds a job without a slot is put back for: 1-5, random, so a backlog does not return in lockstep. */
    public function globalRetryDelay(): int
    {
        return random_int(1, 5);
    }

    /**
     * Atomic INCR + EXPIRE. Redis: one Lua call (a crash between the two commands would leave a
     * counter without TTL). Other stores: `add` + `increment` — atomic on array / database-free
     * drivers used in tests; on file/database stores a lost update can only let one extra message through.
     */
    private function incrementWithTtl(string $key, int $ttlSeconds): int
    {
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            $count = $store->connection()->eval(
                "local n = redis.call('INCR', KEYS[1]) if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return n",
                1,
                $store->getPrefix().$key,
                $ttlSeconds,
            );

            return (int) $count;
        }

        Cache::add($key, 0, $ttlSeconds);
        $count = Cache::increment($key);

        // The key expired between add() and increment(): start the second's counter over.
        if (! is_int($count)) {
            Cache::put($key, 1, $ttlSeconds);

            return 1;
        }

        return $count;
    }

    /**
     * Reserves a send slot and returns 0, or returns the number of seconds
     * after which the caller should retry. The cache lock makes this sliding
     * window atomic across queue workers.
     */
    public function reserve(int|string $chatId): int
    {
        $key = 'telegram:outbound:'.hash('sha256', (string) $chatId);
        $nowMs = now()->getTimestampMs();
        $windowMs = $this->windowSeconds * 1000;

        return Cache::lock($key.':lock', 5)->block(5, function () use ($key, $nowMs, $windowMs): int {
            $stored = Cache::get($key, []);
            $timestamps = is_array($stored)
                ? array_values(array_filter(
                    $stored,
                    static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $nowMs - $windowMs,
                ))
                : [];

            if (count($timestamps) >= $this->maxMessages) {
                $first = $timestamps[0];

                return max(1, (int) ceil(($first + $windowMs - $nowMs) / 1000));
            }

            $timestamps[] = $nowMs;
            Cache::put($key, $timestamps, $this->windowSeconds);

            return 0;
        });
    }
}
