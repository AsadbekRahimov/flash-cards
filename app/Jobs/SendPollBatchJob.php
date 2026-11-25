<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Polls\Services\PollDeliverySender;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollSweeper;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One link of a poll's broadcast chain (docs/35 O3): leases a batch of the poll's pending deliveries and sends them
 * ({@see PollDeliverySender::sendBatch()}), then queues its successor while there may be work left. `polls.workers` chains run per
 * poll ({@see PollLauncher::startWorkers()}), each in its own SLOT (`$slot` 0..workers-1); a chain ends when it finds nothing to
 * lease. Extra chains are harmless for the sends (the lease and the conditional step to `sending` keep every row to one send) but
 * not for the workers of `low`: so a slot holds at most ONE queued job.
 *
 * Unique per (poll, slot) until processing ({@see enqueue()}, docs/35 §1.6а, review of releases 3-4): the lock is taken when a job of
 * the slot is queued - by the launch, by the predecessor (also with the delay of a 429 or of "no slot") or by `polls:sweep` - and
 * released when the worker picks the job up. So "a job of this slot is waiting" is known exactly, however long the queue `low` is
 * or the delay lasts: the sweep restarts only the slots with no waiting job, and a restart while a chain is merely queued or paused
 * adds nothing. A chain that died (a worker killed, `maxExceptions` reached) holds no lock: its slot is started again. The lock
 * lives at most the job's delay + {@see LOCK_SECONDS} - the only case it outlives its job is a queued job lost from Redis (a cleared
 * queue), and then the slot waits that long. The lock is on the default cache store (`redis`, noeviction, docs/19 §4), as the
 * other unique jobs.
 *
 * The job carries only the poll id - no list of deliveries - so a chain never holds stale ids and the queue holds a few jobs, not
 * one per 200 learners. Each message takes its own slot of the bulk budget inside the sender, so no ThrottleTelegramOutbound here.
 * Attempts are unlimited (`tries = 0`): a transport error is retried with `backoff`, capped by `maxExceptions`. The broadcast has a
 * deadline of `polls.broadcast_ttl_hours` from the launch: the sender stops sending after it (the sweep skips what is left), and the
 * worker drops a job an hour after it (`retryUntil`).
 */
final class SendPollBatchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** The longest the lock of a queued job outlives its delay: longer than any tail of the queue `low` we expect. */
    public const LOCK_SECONDS = 3600;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** The chain slot, 0..`polls.workers`-1. Not readonly with a default: a job queued before the slots existed reads as slot 0. */
    public int $slot = 0;

    public function __construct(public readonly int $pollId, public readonly int $notAfter, int $slot = 0)
    {
        $this->slot = $slot;
        $this->onQueue((string) config('polls.queue', 'low'));
    }

    /**
     * Queues the job of a slot unless one is waiting already (the unique lock). True = queued.
     * Used by the launch, the successor and {@see PollSweeper}; the lock is taken before the push and given back if the push fails.
     */
    public static function enqueue(int $pollId, int $notAfter, int $slot = 0, int $delaySeconds = 0): bool
    {
        $job = new self($pollId, $notAfter, $slot);
        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        $lock = new UniqueLock(app(Cache::class));
        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            $lock->release($job);

            throw $e;
        }

        return true;
    }

    /** The end of the broadcast of a poll launched at `$launchedAt`. */
    public static function deadline(CarbonImmutable $launchedAt): CarbonImmutable
    {
        return $launchedAt->addHours(max(1, (int) config('polls.broadcast_ttl_hours', 24)));
    }

    public function uniqueId(): string
    {
        return $this->pollId.':'.$this->slot;
    }

    /** The job's delay + {@see LOCK_SECONDS}: a delayed successor keeps its slot for the whole wait. */
    public function uniqueFor(): int
    {
        $delay = $this->delay;
        $seconds = match (true) {
            is_int($delay) => $delay,
            $delay instanceof DateTimeInterface => max(0, $delay->getTimestamp() - time()),
            default => 0,
        };

        return max(0, $seconds) + self::LOCK_SECONDS;
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::createFromTimestamp($this->notAfter)->addHour();
    }

    public function handle(PollDeliverySender $sender): void
    {
        $outcome = $sender->sendBatch($this->pollId, max(1, (int) config('polls.batch_seconds', 30)));

        if ($outcome->more) {
            self::enqueue($this->pollId, $this->notAfter, $this->slot, $outcome->delaySeconds);
        }
    }
}
