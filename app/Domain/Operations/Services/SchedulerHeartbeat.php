<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Tiny liveness marker written by the scheduler every minute
 * (see routes/console.php) and read by {@see ReadinessProbe}.
 */
final class SchedulerHeartbeat
{
    public const CACHE_KEY = 'ops:scheduler:heartbeat';

    private const TTL_SECONDS = 3600;

    public function __construct(private readonly Repository $cache) {}

    public function beat(): void
    {
        $this->cache->put(self::CACHE_KEY, CarbonImmutable::now()->getTimestamp(), self::TTL_SECONDS);
    }

    public function lastBeatAt(): ?CarbonImmutable
    {
        $timestamp = $this->cache->get(self::CACHE_KEY);

        return is_int($timestamp) ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }
}
