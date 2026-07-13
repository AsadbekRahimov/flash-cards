<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use Illuminate\Support\Facades\Cache;

final class TelegramMessageRateLimiter
{
    public function __construct(
        private readonly int $maxMessages = 20,
        private readonly int $windowSeconds = 60,
    ) {}

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
