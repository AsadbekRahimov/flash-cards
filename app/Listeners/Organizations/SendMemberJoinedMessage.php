<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * DM "You are in center X, Pro is on" / "no seats yet" after a join through an invite link or by an
 * administrator (docs/13 §6.2). Queued after commit; a Telegram outage retries the job, a 403 marks the DM
 * as closed (`dm_blocked_at`) and ends it. Auto-discovered (`app/Listeners`).
 */
final class SendMemberJoinedMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use RunsDmWithoutRetryOnSyncQueue;

    public bool $afterCommit = true;

    // Released jobs (no global Telegram slot) use up attempts: bounded by retryUntil, real failures by maxExceptions.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(private readonly OrganizationMessenger $messenger) {}

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

    public function handle(OrganizationMemberJoined $event): void
    {
        $this->sendDm(fn () => $this->messenger->notifyJoined($event->memberId));
    }
}
