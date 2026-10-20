<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Engagement\Enums\FriendDmKind;
use App\Domain\Telegram\Services\FriendsMessenger;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One DM of the friends feature (docs/13 §7.5): a friend request accepted, a challenge proposed /
 * accepted / finished. Dispatched after commit; the messenger re-reads the row and sends nothing for a
 * state that no longer holds, to a blocked learner or to one whose DM is closed. Released while the
 * bot-wide Telegram budget is exhausted (`tries = 0`, bounded by `retryUntil`; real failures by
 * `maxExceptions`). A challenge result goes to two people as two jobs (`$side`), so a retry never repeats
 * the DM that already went out.
 */
final class SendFriendDmJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        /** `challenger` / `opponent` for a DM that has an addressee besides the default one; null otherwise. */
        public readonly ?string $side = null,
    ) {}

    public static function send(FriendDmKind $kind, int $id, ?string $side = null): void
    {
        self::dispatch($kind->value, $id, $side)->afterCommit();
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(FriendsMessenger $messenger): void
    {
        $kind = FriendDmKind::tryFrom($this->kind);
        if ($kind !== null) {
            $messenger->notify($kind, $this->id, $this->side);
        }
    }
}
