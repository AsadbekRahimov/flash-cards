<?php

declare(strict_types=1);

namespace App\Listeners\Cache;

use Carbon\CarbonImmutable;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Log;

/**
 * The pure cache (store `lru`, docs/19 §4.1) is a failover chain: when the second Redis instance is unreachable every
 * call lands on the array store and the application keeps working from the database. The framework reports that once
 * per outage (`CacheFailedOver`, per FailoverStore instance); this writes it to the log so an operator notices, and
 * throttles a flapping instance to one line a minute per store and process.
 */
final class LogCacheFailover
{
    public const THROTTLE_SECONDS = 60;

    /** @var array<string, int> store name => unix time of the last line */
    private static array $lastLoggedAt = [];

    public function handle(CacheFailedOver $event): void
    {
        $store = $event->storeName ?? 'unknown';
        $now = CarbonImmutable::now()->getTimestamp();

        if (isset(self::$lastLoggedAt[$store]) && $now - self::$lastLoggedAt[$store] < self::THROTTLE_SECONDS) {
            return;
        }

        self::$lastLoggedAt[$store] = $now;

        Log::warning('cache.failed_over', [
            'store' => $store,
            'exception' => $event->exception::class,
            'error' => $event->exception->getMessage(),
        ]);
    }

    /** Forgets the throttle state (tests). */
    public static function reset(): void
    {
        self::$lastLoggedAt = [];
    }
}
