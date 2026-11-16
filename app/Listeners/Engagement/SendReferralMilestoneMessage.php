<?php

declare(strict_types=1);

namespace App\Listeners\Engagement;

use App\Domain\Engagement\Events\ReferralMilestoneReached;
use App\Domain\Engagement\Services\ReferralMilestoneSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Listeners\Organizations\RunsDmWithoutRetryOnSyncQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * DM "N of your friends started — +7 days of Pro" after an inviter's milestone was granted (docs/13 §7.1 "2C.9"). Queued
 * after commit; a Telegram outage retries the listener, a 403 closes the DM. A grant that was skipped sends nothing; a
 * repeated event sends nothing again ({@see ReferralMilestoneSender}). Auto-discovered (`app/Listeners`).
 */
final class SendReferralMilestoneMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use RunsDmWithoutRetryOnSyncQueue;

    public bool $afterCommit = true;

    // Released jobs (no global Telegram slot) use up attempts: bounded by retryUntil, real failures by maxExceptions.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(private readonly ReferralMilestoneSender $sender) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** Fixed when the listener is queued. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ReferralMilestoneReached $event): void
    {
        if ($event->skipped !== null) {
            return;
        }

        $this->sendDm(function () use ($event): void {
            $this->sender->send($event->learnerId, $event->milestone, $event->days, $event->subscriptionId, $event->skipped);
        });
    }
}
