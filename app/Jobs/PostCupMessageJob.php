<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Cup\Services\CupOutbox;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one outgoing message of a cup (a row of `cup_messages`, docs/33 §6.4) - the rules are {@see CupOutbox::deliver()}: claimed by
 * a conditional UPDATE (two workers never post it twice), skipped while the cup is paused, put back while its chat has no slot, never
 * repeated after a timeout. Queued after the commit of the row; `cups:send-outbox` queues again what nobody works on.
 */
final class PostCupMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // Released jobs (no slot of the bot or the chat, a 429) use up attempts; real failures stay capped at 3.
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $cupMessageId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** A summary or a final is worth posting late, but not a day later. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    public function handle(CupOutbox $outbox): void
    {
        $wait = $outbox->deliver($this->cupMessageId, CarbonImmutable::now());
        if ($wait > 0) {
            $this->release($wait + random_int(0, 3));
        }
    }
}
