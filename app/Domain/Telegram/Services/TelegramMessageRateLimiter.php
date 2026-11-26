<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Illuminate\Cache\RedisStore;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Cache;

final class TelegramMessageRateLimiter
{
    /** Bot-wide pause after a 429 (docs/13 §9): the value is the Unix second the pause ends at. */
    public const PAUSE_KEY = 'telegram:outbound:pause_until';

    /** Longest pause one 429 can set, seconds: a bigger `retry_after` is honoured by the released job itself, not by the whole bot. */
    public const MAX_PAUSE_SECONDS = 60;

    /**
     * Set for {@see self::CONTENDED_SECONDS} whenever {@see self::reserveGlobal()} refuses a slot: the normal senders want more than
     * the second has, and the bulk ones ({@see self::reserveGlobalBelow()}) wait until the mark expires (docs/35 O3).
     */
    public const CONTENDED_KEY = 'telegram:outbound:contended';

    public const CONTENDED_SECONDS = 2;

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
     * do not reserve (they never wait), they are only counted ({@see self::consumeGlobal()}).
     *
     * While the bot-wide pause after a 429 lasts ({@see self::pause()}) no slot is given and nothing is counted, whatever the
     * per-second limit (`0` included): Telegram refuses everything until `retry_after` anyway.
     */
    public function reserveGlobal(): bool
    {
        if ($this->globalPauseRemaining() > 0) {
            return false;
        }

        if ($this->globalPerSecond <= 0) {
            return true;
        }

        if ($this->incrementWithTtl($this->globalKey(), 2) <= $this->globalPerSecond) {
            return true;
        }

        // A sender that matters was refused: the bulk senders step aside for a moment (reserveGlobalBelow).
        Cache::put(self::CONTENDED_KEY, 1, self::CONTENDED_SECONDS);

        return false;
    }

    /**
     * A slot for a BULK send that must yield to everything else (a poll broadcast, docs/35 O3): given only while
     *  - there is no pause after a 429;
     *  - no {@see self::reserveGlobal()} caller was refused within the last {@see self::CONTENDED_SECONDS} s (reminders, offers, …
     *    want the budget: the bulk sender stops entirely until they are served);
     *  - the counter of the current second - every background send AND every counted reply ({@see self::consumeGlobal()}) - is
     *    below `$ceiling` (capped by the bot-wide limit). The check and the INCR are one atomic step, so parallel bulk workers
     *    never push the second over the ceiling together.
     * So a broadcast uses the idle part of the budget up to `$ceiling`, the rest (at least `globalPerSecond - $ceiling`) is always
     * free for the others, and under their pressure the broadcast gives back all of it. `$ceiling <= 0` = no ceiling of its own
     * (the bot-wide limit, or nothing when that is off too); pause and contention still apply. Never sets the contention mark.
     */
    public function reserveGlobalBelow(int $ceiling): bool
    {
        if ($this->globalPerSecond > 0) {
            $ceiling = $ceiling > 0 ? min($ceiling, $this->globalPerSecond) : $this->globalPerSecond;
        }

        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            // One round trip: the pause, the contention mark, then the conditional INCR of the second.
            $script = "if tonumber(redis.call('GET', KEYS[2]) or '0') > tonumber(ARGV[1]) then return 0 end "
                ."if redis.call('EXISTS', KEYS[3]) == 1 then return 0 end "
                .'local cap = tonumber(ARGV[2]) '
                ."if cap > 0 and tonumber(redis.call('GET', KEYS[1]) or '0') >= cap then return 0 end "
                ."local n = redis.call('INCR', KEYS[1]) if n == 1 then redis.call('EXPIRE', KEYS[1], 2) end return 1";
            $prefix = $store->getPrefix();
            $args = [$prefix.$this->globalKey(), $prefix.self::PAUSE_KEY, $prefix.self::CONTENDED_KEY, now()->getTimestamp(), max(0, $ceiling)];
            $connection = $store->connection();
            $granted = $connection instanceof PhpRedisConnection
                ? $connection->eval($script, 3, ...$args)
                : $connection->command('eval', [$script, 3, ...$args]);

            return (int) $granted === 1;
        }

        if ($this->globalPauseRemaining() > 0 || Cache::has(self::CONTENDED_KEY)) {
            return false;
        }

        if ($ceiling <= 0) {
            return true;
        }

        $current = Cache::get($this->globalKey());
        if (is_numeric($current) && (int) $current >= $ceiling) {
            return false;
        }

        $this->incrementWithTtl($this->globalKey(), 2);

        return true;
    }

    /** Waits for a {@see self::reserveGlobalBelow()} slot, at most `$maxWaitMs`; false = none came, postpone the work. */
    public function awaitGlobalBelow(int $ceiling, int $maxWaitMs = 2000): bool
    {
        $deadline = hrtime(true) + $maxWaitMs * 1_000_000;

        while (! $this->reserveGlobalBelow($ceiling)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(50_000);
        }

        return true;
    }

    /**
     * Counts one send that does not wait (a reply to an incoming update, docs/34 §4в п. 5): the same per-second counter as
     * {@see self::reserveGlobal()}, INCR only, never a refusal. Background sends of the same second see fewer free slots, so
     * replies and waves together stay under Telegram's ~30/s. A no-op when the limit is off.
     */
    public function consumeGlobal(): void
    {
        if ($this->globalPerSecond <= 0) {
            return;
        }

        $this->incrementWithTtl($this->globalKey(), 2);
    }

    /**
     * Telegram answered 429 with `retry_after` (docs/34 §4в п. 2): sends to `$chatId` (when the refused call had a chat) stop
     * for min(retry_after, {@see self::MAX_PAUSE_SECONDS}) seconds, shared by every worker through the cache store (Redis).
     * Background sends of the whole bot stop too, unless the refused chat is a group: a group has its own limit
     * (20 messages a minute), and its 429 says nothing about the bot-wide budget, so one busy group must not hold every
     * learner's DM. A 429 in a private chat or on a call without a chat pauses the whole bot. A pause is only ever extended,
     * never shortened by a later, smaller `retry_after`.
     */
    public function pause(int $retryAfter, int|string|null $chatId = null): void
    {
        $seconds = max(1, min($retryAfter, self::MAX_PAUSE_SECONDS));
        $until = now()->getTimestamp() + $seconds;
        $chat = $chatId !== null && (string) $chatId !== '' ? $chatId : null;

        if ($chat === null || ! self::isGroupChat($chat)) {
            $this->extendPause(self::PAUSE_KEY, $until, $seconds);
        }

        if ($chat !== null) {
            $this->extendPause($this->chatPauseKey($chat), $until, $seconds);
        }
    }

    /**
     * Group, supergroup or channel: a negative id, or a `@username` (the Bot API accepts a username only for a channel or
     * a supergroup). Private chats have positive ids.
     */
    public static function isGroupChat(int|string $chatId): bool
    {
        if (is_int($chatId)) {
            return $chatId < 0;
        }

        return str_starts_with($chatId, '@') || (is_numeric($chatId) && (int) $chatId < 0);
    }

    /** Seconds left of the bot-wide pause after a 429, 0 = none. */
    public function globalPauseRemaining(): int
    {
        return $this->remaining(self::PAUSE_KEY);
    }

    /** Seconds left of the pause of one chat after a 429 in it, 0 = none. */
    public function chatPauseRemaining(int|string $chatId): int
    {
        return $this->remaining($this->chatPauseKey($chatId));
    }

    private function globalKey(): string
    {
        return 'telegram:outbound:global:'.now()->getTimestamp();
    }

    private function chatPauseKey(int|string $chatId): string
    {
        return 'telegram:outbound:pause:'.hash('sha256', (string) $chatId);
    }

    private function remaining(string $key): int
    {
        $until = Cache::get($key);

        if (! is_numeric($until)) {
            return 0;
        }

        return max(0, (int) $until - now()->getTimestamp());
    }

    /**
     * Sets `$key` to `$until` (TTL `$ttlSeconds`) unless it already holds a later instant. Redis: one Lua call, so two workers
     * that got a 429 at once cannot shorten each other's pause. Other stores (tests): read and write.
     */
    private function extendPause(string $key, int $until, int $ttlSeconds): void
    {
        $store = Cache::getStore();

        if ($store instanceof RedisStore) {
            $script = "local cur = tonumber(redis.call('GET', KEYS[1]) or '0') or 0 "
                ."if tonumber(ARGV[1]) > cur then redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2]) return 1 end return 0";
            $connection = $store->connection();
            $connection instanceof PhpRedisConnection
                ? $connection->eval($script, 1, $store->getPrefix().$key, $until, $ttlSeconds)
                : $connection->command('eval', [$script, 1, $store->getPrefix().$key, $until, $ttlSeconds]);

            return;
        }

        $current = Cache::get($key);
        if (is_numeric($current) && (int) $current >= $until) {
            return;
        }

        Cache::put($key, $until, $ttlSeconds);
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

    /**
     * Seconds a job without a slot is put back for: 1-5, random, so a backlog does not return in lockstep. During the pause
     * after a 429: the rest of the pause plus 0-3 s, so released jobs do not come back only to be released again.
     */
    public function globalRetryDelay(): int
    {
        $paused = $this->globalPauseRemaining();

        return $paused > 0 ? $paused + random_int(0, 3) : random_int(1, 5);
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
            $script = "local n = redis.call('INCR', KEYS[1]) if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return n";
            $connection = $store->connection();
            // phpredis: Laravel's wrapper maps (script, numKeys, ...args) onto the client's eval(script, args, numKeys);
            // predis takes the same order natively.
            $count = $connection instanceof PhpRedisConnection
                ? $connection->eval($script, 1, $store->getPrefix().$key, $ttlSeconds)
                : $connection->command('eval', [$script, 1, $store->getPrefix().$key, $ttlSeconds]);

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
     *
     * While this chat is paused after a 429 ({@see self::pause()}) the rest of the pause is returned and no slot is recorded.
     * The bot-wide pause is not checked here: a reply to an update does not wait for it (docs/34 §4в п. 5).
     */
    public function reserve(int|string $chatId): int
    {
        $paused = $this->chatPauseRemaining($chatId);
        if ($paused > 0) {
            return $paused;
        }

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
