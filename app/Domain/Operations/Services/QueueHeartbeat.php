<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Jobs\QueueHeartbeatJob;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Per-queue worker liveness marker: {@see QueueHeartbeatJob} (dispatched every minute
 * by the scheduler) writes it when a worker actually picks the job up, {@see ReadinessProbe}
 * reads it. A stopped worker or a queue stuck behind a backlog leaves the marker stale.
 */
final class QueueHeartbeat
{
    public const KEY_PREFIX = 'queue:heartbeat:';

    private const TTL_SECONDS = 3600;

    public function __construct(private readonly Repository $cache) {}

    public function beat(string $queue): void
    {
        $this->cache->put(self::KEY_PREFIX.$queue, CarbonImmutable::now()->getTimestamp(), self::TTL_SECONDS);
    }

    public function lastBeatAt(string $queue): ?CarbonImmutable
    {
        $timestamp = $this->cache->get(self::KEY_PREFIX.$queue);

        // RedisStore returns numeric values as strings.
        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }
}
