<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\GroupBotRemoval;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class SendTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    // Per-chat and global releases use up attempts; real failures stay capped.
    public int $tries = 0;

    public int $maxExceptions = 5;

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function __construct(
        public readonly int|string $chatId,
        public readonly string $text,
        public readonly ?string $parseMode = null,
        public readonly ?array $replyMarkup = null,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new ThrottleTelegramOutbound];
    }

    /** A deferred message that could not go out for 6 hours (no global slot, per-chat limit) is stale: dropped, not retried for ever. */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::now()->addHours(6);
    }

    /**
     * `$reachability` and `$groups` are resolved by the container on the queue; a direct call (a unit test of the limiter) may leave
     * them out - they are taken from the container only when a 403 needs them.
     */
    public function handle(
        IrazasyedTelegramClient $telegram,
        TelegramMessageRateLimiter $rateLimiter,
        ?DmReachability $reachability = null,
        ?GroupBotRemoval $groups = null,
    ): void {
        $retryAfter = $rateLimiter->reserve($this->chatId);
        if ($retryAfter > 0) {
            $this->release($retryAfter);

            return;
        }

        try {
            $telegram->sendImmediately(
                $this->chatId,
                $this->text,
                $this->parseMode,
                $this->replyMarkup,
            );
        } catch (TelegramForbiddenException $e) {
            // Bot blocked / kicked / dialog never started: retrying (up to $tries
            // times) can never succeed, so finish the job without failing it.
            // The chat id is logged only as a keyed fingerprint, never in clear.
            Log::warning('telegram.deferred_message_forbidden', [
                'reason' => $e->reason,
                'chat' => $this->chatFingerprint(),
            ]);

            // docs/34 §4в п. 8: a private chat (id > 0) is the learner's - the DM is closed; a negative id is a group the bot is
            // no longer in. A `@channel` name is neither.
            if (is_int($this->chatId) || ctype_digit(ltrim($this->chatId, '-'))) {
                $chatId = (int) $this->chatId;

                if ($chatId > 0) {
                    ($reachability ?? app(DmReachability::class))->markUnreachable($chatId, $e->reason);
                } elseif ($chatId < 0) {
                    ($groups ?? app(GroupBotRemoval::class))->disableChat($chatId, $e, 'deferred_message');
                }
            }
        }
    }

    private function chatFingerprint(): string
    {
        return substr(hash_hmac('sha256', (string) $this->chatId, (string) config('app.key')), 0, 12);
    }
}
