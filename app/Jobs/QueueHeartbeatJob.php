<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Operations\Services\QueueHeartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Featherweight job proving a worker consumes the given queue (readiness `queue` check).
 * Unique per queue so a dead worker does not accumulate one job per minute.
 */
final class QueueHeartbeatJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $heartbeatQueue)
    {
        $this->onQueue($heartbeatQueue);
    }

    public function uniqueId(): string
    {
        return $this->heartbeatQueue;
    }

    public function handle(QueueHeartbeat $heartbeat): void
    {
        $heartbeat->beat($this->heartbeatQueue);
    }
}
