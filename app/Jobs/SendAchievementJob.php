<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Services\AchievementSender;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The card DM of one new achievement (docs/13 §7.4), queued by AchievementService::unlock after the
 * insert. Takes a slot of the bot-wide send budget first ({@see ThrottleTelegramOutbound}); at most
 * once per achievement ({@see AchievementSender}).
 */
final class SendAchievementJob implements ShouldQueue
{
    use Queueable;

    // A released job (no global Telegram slot) uses up an attempt: attempts are unlimited, real
    // failures are capped, and a job that waited too long is dropped by `retryUntil`.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $achievementId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(AchievementSender $sender): void
    {
        $sender->send($this->achievementId);
    }
}
