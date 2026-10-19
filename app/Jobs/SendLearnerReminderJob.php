<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Learners\Services\ReminderSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One personal reminder for one learner, queued by {@see ReminderDispatcher}
 * after it claimed `next_reminder_at`. `$scheduledFor` is the instant that was claimed: the
 * sender drops the job when the queue delivered it too late (docs/12 §7.5).
 *
 * At most once across retries and duplicate deliveries: `notifications_log` is written before the
 * Telegram call ({@see ReminderSender}), so a retry after an ambiguous error only skips; a
 * Telegram 403 / `chat not found` ends the job without a retry. Runs in the `default` queue.
 */
final class SendLearnerReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $learnerId,
        public readonly CarbonImmutable $scheduledFor,
    ) {}

    public function handle(ReminderSender $sender): void
    {
        $sender->send($this->learnerId, $this->scheduledFor);
    }
}
