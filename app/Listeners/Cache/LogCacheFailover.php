<?php

declare(strict_types=1);

namespace App\Listeners\Cache;

use App\Domain\Operations\Events\CacheCircuitClosed;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Log;

/**
 * The pure cache (store `lru`, docs/19 §4.1) is a failover chain behind a circuit breaker: when the second Redis instance
 * is unreachable or hung the breaker opens and every call of every process lands on the array store, and the application
 * keeps working from the database. The chain dispatches `CacheFailedOver` once per OPENING of the breaker - the marker is
 * shared by all the processes of the host, so a php-fpm request does not add a line of its own - and `CacheCircuitClosed`
 * when a probe reaches the instance again. Both go to the log, so an operator sees when the outage began, that it goes on
 * (a line per window while it lasts) and when it ended. No throttling here: the breaker is the throttle.
 */
final class LogCacheFailover
{
    public function handle(CacheFailedOver $event): void
    {
        Log::warning('cache.failed_over', [
            'store' => $event->storeName ?? 'unknown',
            'exception' => $event->exception::class,
            'error' => $event->exception->getMessage(),
        ]);
    }

    public function handleRecovery(CacheCircuitClosed $event): void
    {
        Log::info('cache.recovered', ['store' => $event->storeName]);
    }
}
