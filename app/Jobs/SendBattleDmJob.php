<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Practice\Enums\BattleDmKind;
use App\Domain\Telegram\Services\BattleMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One DM of the battle (docs/16 §8.7): the invitation to the friend. Dispatched after the commit of the invitation
 * ({@see send()}); the messenger reads the battle again and sends nothing for an invitation that is no longer open, to a
 * blocked learner or to one whose DM is closed. Released while the bot-wide Telegram budget is exhausted (`tries = 0`,
 * bounded by `retryUntil`: an invitation lives 5 minutes, an hour later there is nothing to say; real failures by
 * `maxExceptions`), as {@see SendFriendDmJob}.
 */
final class SendBattleDmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $kind,
        public readonly int $battleId,
    ) {}

    /** Queues the DM once the surrounding transaction commits (outside one: at once). */
    public static function send(BattleDmKind $kind, int $battleId): void
    {
        self::dispatch($kind->value, $battleId)->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHour();
    }

    public function handle(BattleMessenger $messenger): void
    {
        $kind = BattleDmKind::tryFrom($this->kind);
        if ($kind !== null) {
            $messenger->notify($kind, $this->battleId);
        }
    }
}
