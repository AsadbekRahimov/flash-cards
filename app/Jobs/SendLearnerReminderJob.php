<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Learners\Services\ReminderSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One personal reminder for one learner, queued by {@see ReminderDispatcher}
 * after it claimed `next_reminder_at`. `$scheduledFor` is the instant that was claimed: the
 * sender drops the job when the queue delivered it too late (docs/12 §7.5).
 *
 * At most once across retries and duplicate deliveries: `notifications_log` is written before the
 * Telegram call ({@see ReminderSender}), so a retry after an ambiguous error only skips; a
 * Telegram 403 / `chat not found` ends the job without a retry. Runs in the `default` queue and takes a slot of
 * the bot-wide send budget first ({@see ThrottleTelegramOutbound}).
 */
final class SendLearnerReminderJob implements ShouldQueue
{
    use Queueable;

    // A released job (no global Telegram slot, see ThrottleTelegramOutbound) uses up an attempt, so
    // attempts are unlimited and only real exceptions count; a job that waits too long ends as
    // SkippedStale in the sender.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $learnerId,
        public readonly CarbonImmutable $scheduledFor,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** The sender drops a reminder later than `max_delay_minutes` after its slot anyway: stop retrying then. */
    public function retryUntil(): DateTimeInterface
    {
        return $this->scheduledFor->addMinutes(max(15, (int) config('learning.reminders.max_delay_minutes', 120)));
    }

    public function handle(ReminderSender $sender): void
    {
        $sender->send($this->learnerId, $this->scheduledFor);
    }
}
