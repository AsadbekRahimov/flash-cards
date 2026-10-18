<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\SendTelegramMessageJob;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;

class IrazasyedTelegramClient implements TelegramClient
{
    public function __construct(
        private readonly Api $api,
        private readonly TelegramKeyboardFactory $keyboards,
        private readonly TelegramMessageRateLimiter $rateLimiter,
    ) {}

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void {
        $retryAfter = $this->rateLimiter->reserve($chatId);
        if ($retryAfter > 0) {
            SendTelegramMessageJob::dispatch($chatId, $text, $parseMode, $replyMarkup)
                ->onQueue((string) config('telegram.queue', 'high'))
                ->delay(now()->addSeconds($retryAfter));

            return;
        }

        $this->sendImmediately($chatId, $text, $parseMode, $replyMarkup);
    }

    /**
     * Sends a message without reserving another rate-limit slot.
     * Only the deferred queue job should call this method directly.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramForbiddenException when Telegram answers 403
     */
    public function sendImmediately(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        try {
            $this->api->sendMessage($params);
        } catch (TelegramResponseException $e) {
            // Keep the SDK behind the TelegramClient boundary: callers only need to
            // know that the recipient is unreachable (blocked bot / no dialog).
            if ($e->getCode() === 403 || $e->getHttpStatusCode() === 403) {
                throw TelegramForbiddenException::fromDescription($e->getMessage(), $e);
            }

            throw $e;
        }
    }

    public function sendWebAppButton(
        int|string $chatId,
        string $text,
        string $buttonText,
        string $url,
        ?string $parseMode = null,
    ): void {
        $this->sendMessage(
            chatId: $chatId,
            text: $text,
            parseMode: $parseMode,
            replyMarkup: $this->keyboards->webAppButton($buttonText, $url),
        );
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->api->answerCallbackQuery(['callback_query_id' => $callbackQueryId]);
    }

    public function setWebhook(string $url, string $secretHeader): bool
    {
        return (bool) $this->api->setWebhook([
            'url' => $url,
            'secret_token' => $secretHeader,
            'allowed_updates' => ['message', 'my_chat_member', 'chat_member', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): bool
    {
        return (bool) $this->api->deleteWebhook();
    }
}
