<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Organizations\Events\OrganizationMemberJoined;
use App\Domain\Telegram\Services\OrganizationMessenger;
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

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly OrganizationMessenger $messenger) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(OrganizationMemberJoined $event): void
    {
        $this->messenger->notifyJoined($event->memberId);
    }
}
