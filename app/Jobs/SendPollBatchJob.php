<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Polls\Services\PollDeliverySender;
use App\Domain\Polls\Services\PollLauncher;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One link of a poll's broadcast chain (docs/35 O3): leases a batch of the poll's pending deliveries and sends them
 * ({@see PollDeliverySender::sendBatch()}), then queues its successor while there may be work left. `polls.workers` chains run per
 * poll ({@see PollLauncher::startWorkers()}); a chain ends when it finds nothing to lease. Extra chains
 * (a retried job, the sweep) are harmless: the lease and the conditional step to `sending` keep every row to one send.
 *
 * The job carries only the poll id - no list of deliveries - so a chain never holds stale ids and the queue holds a few jobs, not
 * one per 200 learners. Each message takes its own slot of the bulk budget inside the sender, so no ThrottleTelegramOutbound here.
 * Attempts are unlimited (`tries = 0`): a transport error is retried with `backoff`, capped by `maxExceptions`. The broadcast has a
 * deadline of `polls.broadcast_ttl_hours` from the launch: the sender stops sending after it (the sweep skips what is left), and the
 * worker drops a job an hour after it (`retryUntil`).
 */
final class SendPollBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $pollId, public readonly int $notAfter)
    {
        $this->onQueue((string) config('polls.queue', 'low'));
    }

    /** The end of the broadcast of a poll launched at `$launchedAt`. */
    public static function deadline(CarbonImmutable $launchedAt): CarbonImmutable
    {
        return $launchedAt->addHours(max(1, (int) config('polls.broadcast_ttl_hours', 24)));
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->notAfter)->addHour();
    }

    public function handle(PollDeliverySender $sender): void
    {
        $outcome = $sender->sendBatch($this->pollId, max(1, (int) config('polls.batch_seconds', 30)));

        if ($outcome->more) {
            self::dispatch($this->pollId, $this->notAfter)->delay($outcome->delaySeconds);
        }
    }
}
