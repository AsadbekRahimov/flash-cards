<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\Middleware\ThrottleTelegramOutbound;
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

    public function handle(
        IrazasyedTelegramClient $telegram,
        TelegramMessageRateLimiter $rateLimiter,
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
        }
    }

    private function chatFingerprint(): string
    {
        return substr(hash_hmac('sha256', (string) $this->chatId, (string) config('app.key')), 0, 12);
    }
}
