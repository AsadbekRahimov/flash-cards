<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\LeagueResultSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The week-result DM of one league member (docs/13 §7.3), queued by LeagueService::closeWeek
 * after the closing transaction committed. Takes a slot of the bot-wide send budget first
 * ({@see ThrottleTelegramOutbound}); at most once per member ({@see LeagueResultSender}).
 */
final class SendLeagueResultJob implements ShouldQueue
{
    use Queueable;

    // A released job (no global Telegram slot) uses up an attempt: attempts are unlimited, real
    // failures are capped, and a job that waited too long is dropped by `retryUntil`.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $memberId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(max(1, (int) config('leagues.notify_ttl_hours', 12)));
    }

    public function handle(LeagueResultSender $sender): void
    {
        $sender->send($this->memberId);
    }
}
