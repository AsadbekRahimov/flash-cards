<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Cup\Enums\CupDuelDmKind;
use App\Domain\Telegram\Services\CupDuelMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One DM of a duel of a cup (docs/33 §5.7, D2): the private challenge to the one challenged, "accepted" to the challenger. Dispatched
 * after the commit ({@see send()}); the messenger reads the duel again and says nothing that no longer holds. Released while the
 * Telegram budget is exhausted (`tries = 0`, bounded by `retryUntil`: a challenge lives 10 minutes, the room of the match 30 seconds;
 * real failures by `maxExceptions`), as {@see SendBattleDmJob}.
 */
final class SendCupDuelDmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly string $kind,
        public readonly int $duelId,
    ) {}

    /** Queues the DM once the surrounding transaction commits (outside one: at once). */
    public static function send(CupDuelDmKind $kind, int $duelId): void
    {
        self::dispatch($kind->value, $duelId)->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** The news is stale long before this: the challenge is over in 10 minutes. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(15);
    }

    public function handle(CupDuelMessenger $messenger): void
    {
        $kind = CupDuelDmKind::tryFrom($this->kind);
        if ($kind !== null) {
            $messenger->notify($kind, $this->duelId);
        }
    }
}
