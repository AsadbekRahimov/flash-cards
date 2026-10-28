<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\LeagueResultSender;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The week-result DMs of a batch of league members (docs/13 §7.3), queued by LeagueService::closeWeek
 * after the week was closed — `leagues.notify_batch_size` members per job instead of one job each.
 * Every message takes its own slot of the bot-wide send budget inside {@see LeagueResultSender::sendBatch()}
 * (so no {@see Middleware\ThrottleTelegramOutbound} here: one slot per job would be wrong for a batch);
 * at most one DM per member and closed week whatever happens to the job.
 *
 * When the budget runs dry or `leagues.notify_batch_seconds` are spent, the members not reached yet go
 * into a fresh job with a short delay — this job ends successfully, so a long mailing never trips the
 * worker's timeout. Attempts are unlimited (a real failure of the loop — the database away — is retried
 * with `backoff`, capped by `maxExceptions`); a batch that waited too long is dropped by `retryUntil`.
 */
final class SendLeagueResultsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    /** @param  list<int>  $memberIds */
    public function __construct(public readonly array $memberIds) {}

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(max(1, (int) config('leagues.notify_ttl_hours', 12)));
    }

    public function handle(LeagueResultSender $sender, TelegramMessageRateLimiter $limiter): void
    {
        $outcome = $sender->sendBatch($this->memberIds, max(0, (int) config('leagues.notify_batch_seconds', 30)));

        if ($outcome['remaining'] !== []) {
            self::dispatch($outcome['remaining'])->delay($limiter->globalRetryDelay());
        }
    }
}
