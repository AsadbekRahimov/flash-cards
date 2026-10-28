<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\LeagueResultSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The week-result DM of ONE league member — the format of the jobs before the batches of P1-2.
 *
 * @deprecated Compatibility shim for ONE release (17.12); nothing dispatches it any more, new code queues
 *             {@see SendLeagueResultsJob}. Jobs of this class may still wait in the queue while a release is
 *             rolled out, and the worker unserializes them with the class of the NEW code, so the class stays
 *             and does what it always did: one member through a slot of the bot-wide send budget
 *             ({@see ThrottleTelegramOutbound}), at most once per member and closed week by the very marker the
 *             batches use ({@see LeagueResultSender::send()}), so an old job and a batch never double a DM.
 *             REMOVE the class, its test and the note in docs/DEVOPS_GUIDE.md in the release after the first one
 *             that ships it: the payload of an old job dies by its own `retryUntil` (at most
 *             `LEAGUES_NOTIFY_TTL_HOURS`, 12 h) after it was queued.
 */
final class SendLeagueResultJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $memberId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function handle(LeagueResultSender $sender): void
    {
        $sender->send($this->memberId);
    }
}
