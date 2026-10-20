<?php

declare(strict_types=1);

namespace App\Listeners\Organizations;

use App\Domain\Organizations\Events\OrganizationMemberSeated;
use App\Domain\Telegram\Services\OrganizationMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * DM "A seat is free, Pro is on" to a member who was waiting for one (docs/13 §1.3, §6.2). Queued after
 * commit; see {@see SendMemberJoinedMessage} for the delivery rules.
 */
final class SendMemberSeatedMessage implements ShouldQueue
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

    public function handle(OrganizationMemberSeated $event): void
    {
        $this->messenger->notifySeated($event->memberId);
    }
}
