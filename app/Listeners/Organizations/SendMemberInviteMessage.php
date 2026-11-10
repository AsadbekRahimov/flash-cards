<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Organizations\Events\OrganizationMemberInvited;
use App\Domain\Telegram\Services\OrganizationMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * DM "Center X invites you — Join / Decline" after the staff offered a membership (audit 2026-11-10 M2). Queued after commit; a
 * Telegram outage retries the job, a 403 marks the DM as closed and ends it. The offer is read again at send time: one that was
 * answered or revoked meanwhile is not sent. Auto-discovered (`app/Listeners`).
 */
final class SendMemberInviteMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use RunsDmWithoutRetryOnSyncQueue;

    public bool $afterCommit = true;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(private readonly OrganizationMessenger $messenger) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrganizationMemberInvited $event): void
    {
        $this->sendDm(fn () => $this->messenger->offerMembership($event->memberId));
    }
}
