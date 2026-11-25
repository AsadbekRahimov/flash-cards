<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learners\Services\LessonNudgeScheduler;
use App\Domain\Learners\Services\LessonNudgeSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The bot's reminder after a lesson (docs/31 §5б) for one learner and one local day, queued by {@see LessonNudgeScheduler} with a delay.
 * At most once across retries and repeated deliveries: the `notifications_log` row of {@see LessonNudgeSender}, written before the
 * Telegram call. Released jobs (no Telegram slot, a 429 - {@see ThrottleTelegramOutbound}) use up attempts, so `tries = 0` and the
 * time limit {@see RETRY_HOURS} after queuing (the sender drops a job of another local day anyway).
 */
final class SendLessonNudgeJob implements ShouldQueue
{
    use Queueable;

    public const RETRY_HOURS = 6;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** ISO-8601 instant after which the job is not retried. */
    public readonly string $retryUntilIso;

    /** @param  string  $localDate  the learner's local day (`Y-m-d`) of the lesson */
    public function __construct(
        public readonly int $learnerId,
        public readonly string $localDate,
    ) {
        $this->retryUntilIso = CarbonImmutable::now()
            ->addMinutes(LessonNudgeScheduler::delayMinutes())
            ->addHours(self::RETRY_HOURS)
            ->toIso8601String();
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

    public function handle(LessonNudgeSender $sender): void
    {
        $sender->send($this->learnerId, $this->localDate);
    }
}
