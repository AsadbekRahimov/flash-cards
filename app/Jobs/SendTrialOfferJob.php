<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Billing\Services\TrialOfferSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One offer after the welcome trial (docs/34 §2д) for one (subscription, step, end of the trial), queued by `billing:trial-offers`.
 * At most once across retries and repeated queuing: the marker of {@see TrialOfferSender}. Released jobs (no Telegram slot, a 429) use up
 * attempts, so `tries = 0` and the time limit: {@see RETRY_HOURS} after queuing (the sender drops a stale step anyway).
 */
final class SendTrialOfferJob implements ShouldQueue
{
    use Queueable;

    public const RETRY_HOURS = 12;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** ISO-8601 instant after which the job is not retried. */
    public readonly string $retryUntilIso;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly int $step,
        public readonly string $endsAtIso,
    ) {
        $this->retryUntilIso = CarbonImmutable::now()->addHours(self::RETRY_HOURS)->toIso8601String();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::parse($this->retryUntilIso);
    }

    public function handle(TrialOfferSender $sender): void
    {
        $sender->send($this->subscriptionId, $this->step, CarbonImmutable::parse($this->endsAtIso));
    }
}
