<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Jobs\SendTelegramMessageJob;
use Closure;
use Exception;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\FileUpload\InputFile;

class IrazasyedTelegramClient implements TelegramClient
{
    /** @var list<string> */
    public const ALLOWED_UPDATES = ['message', 'my_chat_member', 'chat_member', 'callback_query', 'pre_checkout_query'];

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
     * @throws TelegramForbiddenException when Telegram answers 403 (or 400 "chat not found")
     * @throws TelegramApiException on any other failed Bot API call
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

        $this->call(fn () => $this->api->sendMessage($params), mapForbidden: true);
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

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendPhoto(
        int|string $chatId,
        string $photoPath,
        ?string $caption = null,
        ?array $replyMarkup = null,
    ): void {
        if (! is_file($photoPath) || ! is_readable($photoPath)) {
            throw new TelegramApiException('Telegram API request failed (sendPhoto): the photo file is not readable');
        }

        $params = [
            'chat_id' => $chatId,
            'photo' => InputFile::create($photoPath, basename($photoPath)),
        ];

        if ($caption !== null) {
            $params['caption'] = $caption;
        }

        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $this->call(fn () => $this->api->sendPhoto($params), mapForbidden: true);
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        $this->call(fn () => $this->api->answerCallbackQuery(['callback_query_id' => $callbackQueryId]));
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        $this->call(fn () => $this->api->editMessageReplyMarkup([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            // An empty `inline_keyboard` is how the Bot API removes the buttons.
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]));
    }

    public function getChatMemberStatus(int|string $chatId, int $userId): string
    {
        $member = $this->call(fn () => $this->api->getChatMember(['chat_id' => $chatId, 'user_id' => $userId]));

        // The SDK wraps the whole response body (`{ok, result: {status, …}}`) into the object.
        $data = $member->toArray();
        $result = is_array($data['result'] ?? null) ? $data['result'] : $data;

        return is_string($result['status'] ?? null) ? $result['status'] : '';
    }

    public function setWebhook(string $url, string $secretHeader): bool
    {
        return (bool) $this->call(fn () => $this->api->setWebhook([
            'url' => $url,
            'secret_token' => $secretHeader,
            // `pre_checkout_query` is answered synchronously by WebhookController (docs/13 §4.1);
            // `successful_payment` / `refunded_payment` arrive as `message`.
            'allowed_updates' => self::ALLOWED_UPDATES,
        ]));
    }

    public function deleteWebhook(): bool
    {
        return (bool) $this->call(fn () => $this->api->deleteWebhook());
    }

    /**
     * @param  list<array{label: string, amount: int}>  $prices
     */
    public function createInvoiceLink(string $title, string $description, string $payload, string $currency, array $prices, ?int $subscriptionPeriod = null): string
    {
        $params = [
            'title' => $title,
            'description' => $description,
            'payload' => $payload,
            'currency' => $currency,
            // The SDK json-encodes `prices` itself. No `provider_token`: Stars have none.
            'prices' => $prices,
        ];

        if ($subscriptionPeriod !== null) {
            $params['subscription_period'] = $subscriptionPeriod;
        }

        $link = $this->call(fn (): string => (string) $this->api->createInvoiceLink($params));

        if ($link === '') {
            throw new TelegramApiException('Telegram API request failed (createInvoiceLink): empty result');
        }

        return $link;
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, ?string $errorMessage = null): void
    {
        $params = ['pre_checkout_query_id' => $preCheckoutQueryId, 'ok' => $ok];

        if (! $ok) {
            $params['error_message'] = $errorMessage ?? 'Payment refused.';
        }

        $this->call(fn () => $this->api->answerPreCheckoutQuery($params));
    }

    public function editUserStarSubscription(int $userId, string $telegramPaymentChargeId, bool $isCanceled): void
    {
        // Not wrapped by the SDK (3.x): raw Bot API call through the same boundary.
        $this->call(fn () => $this->api->post('editUserStarSubscription', [
            'user_id' => $userId,
            'telegram_payment_charge_id' => $telegramPaymentChargeId,
            'is_canceled' => $isCanceled,
        ]));
    }

    public function refundStarPayment(int $userId, string $telegramPaymentChargeId): void
    {
        $this->call(fn () => $this->api->post('refundStarPayment', [
            'user_id' => $userId,
            'telegram_payment_charge_id' => $telegramPaymentChargeId,
        ]));
    }

    public function getStarTransactions(int $offset = 0, int $limit = 100): array
    {
        $response = $this->call(fn () => $this->api->post('getStarTransactions', [
            'offset' => max(0, $offset),
            'limit' => max(1, min(100, $limit)),
        ]));

        $result = $response->getResult();
        $transactions = is_array($result) ? ($result['transactions'] ?? null) : null;

        return array_values(array_filter(is_array($transactions) ? $transactions : [], 'is_array'));
    }

    /**
     * Single exit point for SDK calls: nothing from the SDK/Guzzle leaves this
     * class, because their messages contain the bot token (see
     * {@see TelegramApiException}).
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws TelegramForbiddenException
     * @throws TelegramApiException
     */
    private function call(Closure $call, bool $mapForbidden = false): mixed
    {
        try {
            return $call();
        } catch (TelegramResponseException $e) {
            // Keep the SDK behind the TelegramClient boundary: callers only need to
            // know that the recipient is unreachable (blocked bot / no dialog).
            if ($mapForbidden && ($e->getCode() === 403 || $e->getHttpStatusCode() === 403)) {
                throw TelegramForbiddenException::fromDescription($e->getMessage());
            }

            // A deleted account answers 400 "chat not found", which is just as final as a 403.
            if ($mapForbidden
                && ($e->getCode() === 400 || $e->getHttpStatusCode() === 400)
                && str_contains(strtolower($e->getMessage()), 'chat not found')
            ) {
                throw TelegramForbiddenException::fromDescription($e->getMessage());
            }

            throw TelegramApiException::fromSdk($e);
        } catch (Exception $e) {
            throw TelegramApiException::fromSdk($e);
        }
    }
}
