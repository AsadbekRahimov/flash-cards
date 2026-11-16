<?php

declare(strict_types=1);

namespace App\Listeners\Engagement;

use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Services\MonthWinnerSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use App\Listeners\Organizations\RunsDmWithoutRetryOnSyncQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * DM "you took place N of the rating of the month" to the winners of a month the administrator confirmed (docs/13 §7.6).
 * Queued after commit; a Telegram outage retries the listener, a 403 closes the DM, a repeated event sends nothing again
 * ({@see MonthWinnerSender}). Auto-discovered (`app/Listeners`).
 */
final class SendMonthWinnerMessages implements ShouldQueue
{
    use InteractsWithQueue;
    use RunsDmWithoutRetryOnSyncQueue;

    public bool $afterCommit = true;

    // Released jobs (no global Telegram slot) use up attempts: bounded by retryUntil, real failures by maxExceptions.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(private readonly MonthWinnerSender $sender) {}

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

    public function handle(LeagueMonthConfirmed $event): void
    {
        $this->sendDm(function () use ($event): void {
            $this->sender->sendAll($event->monthId, $event->winners);
        });
    }
}
