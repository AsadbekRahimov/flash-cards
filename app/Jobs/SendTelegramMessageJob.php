<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendTelegramMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 25;

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function __construct(
        public readonly int|string $chatId,
        public readonly string $text,
        public readonly ?string $parseMode = null,
        public readonly ?array $replyMarkup = null,
    ) {}

    public function handle(
        IrazasyedTelegramClient $telegram,
        TelegramMessageRateLimiter $rateLimiter,
    ): void {
        $retryAfter = $rateLimiter->reserve($this->chatId);
        if ($retryAfter > 0) {
            $this->release($retryAfter);

            return;
        }

        $telegram->sendImmediately(
            $this->chatId,
            $this->text,
            $this->parseMode,
            $this->replyMarkup,
        );
    }
}
