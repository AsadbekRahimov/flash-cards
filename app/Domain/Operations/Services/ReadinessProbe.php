<?php

declare(strict_types=1);

namespace App\Domain\Operations\Services;

use App\Domain\Operations\DTO\ReadinessReport;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Log;

/**
 * Dependency checks behind `GET /health/ready`.
 *
 * The report carries only "ok"/"fail" per check: exception messages, hosts
 * and versions are logged internally and never exposed to the caller.
 */
final class ReadinessProbe
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RedisFactory $redis,
        private readonly QueueManager $queues,
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    public function run(): ReadinessReport
    {
        $checks = [
            'db' => $this->guard('db', $this->checkDatabase(...)),
        ];

        $redisConnections = $this->redisConnections();
        if ($redisConnections !== []) {
            $checks['redis'] = $this->guard('redis', fn (): bool => $this->checkRedis($redisConnections));
        }

        $checks['scheduler'] = $this->guard('scheduler', $this->checkScheduler(...));
        $checks['queue'] = $this->guard('queue', $this->checkQueue(...));

        return new ReadinessReport($checks);
    }

    /**
     * @param  callable(): bool  $check
     */
    private function guard(string $name, callable $check): string
    {
        try {
            if ($check()) {
                return ReadinessReport::OK;
            }
        } catch (\Throwable $e) {
            Log::warning('readiness.check_failed', [
                'check' => $name,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return ReadinessReport::FAIL;
        }

        Log::warning('readiness.check_failed', ['check' => $name]);

        return ReadinessReport::FAIL;
    }

    private function checkDatabase(): bool
    {
        $this->db->select('select 1');

        return true;
    }

    /**
     * @param  list<string>  $connections
     */
    private function checkRedis(array $connections): bool
    {
        foreach ($connections as $connection) {
            // phpredis throws on failure; an explicit false is also a failure.
            if ($this->redis->connection($connection)->ping() === false) {
                return false;
            }
        }

        return true;
    }

    private function checkScheduler(): bool
    {
        $lastBeat = $this->heartbeat->lastBeatAt();
        if ($lastBeat === null) {
            return false;
        }

        $maxAge = max(1, (int) config('operations.readiness.heartbeat_max_age_seconds', 180));

        return $lastBeat->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($maxAge));
    }

    private function checkQueue(): bool
    {
        $threshold = (int) config('operations.readiness.queue_max_size', 1000);

        $queues = array_filter(
            (array) config('operations.readiness.queues', ['high', 'default']),
            'is_string',
        );

        foreach ($queues as $queue) {
            if ($this->queues->size($queue) > $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * Redis connections that cache / queue actually use (empty = Redis not in play).
     *
     * @return list<string>
     */
    private function redisConnections(): array
    {
        $connections = [];

        $cacheStore = (string) config('cache.default');
        if (config("cache.stores.{$cacheStore}.driver") === 'redis') {
            $connections[] = (string) config("cache.stores.{$cacheStore}.connection", 'default');
        }

        $queueConnection = (string) config('queue.default');
        if (config("queue.connections.{$queueConnection}.driver") === 'redis') {
            $connections[] = (string) config("queue.connections.{$queueConnection}.connection", 'default');
        }

        return array_values(array_unique($connections));
    }
}
