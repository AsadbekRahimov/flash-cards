<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Game\Services\GameCardSender;
use App\Domain\Game\Services\Runs\RunFinisher;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The card DM "I got to {city}" for the first win over the boss of a city (docs/13 §7.4, docs/18 §12.3.4), queued by
 * {@see RunFinisher} after the commit. Takes a slot of the bot-wide send budget first
 * ({@see ThrottleTelegramOutbound}); at most once per learner and city ({@see GameCardSender}). Retries like {@see SendAchievementJob}.
 */
final class SendGameCardJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $learnerId, public readonly int $stageId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(GameCardSender $sender): void
    {
        $sender->sendBossCard($this->learnerId, $this->stageId);
    }
}
