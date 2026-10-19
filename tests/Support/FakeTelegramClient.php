<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Closure;

/**
 * Recording TelegramClient for tests: every outgoing call is kept in `$sent`,
 * `$answered` and `$edits` so a test can assert on text, buttons and order.
 */
final class FakeTelegramClient implements TelegramClient
{
    /** @var list<array{chat_id:int|string, text:string, button:string|null, url:string|null, markup:array<string, mixed>|null}> */
    public array $sent = [];

    /** @var list<string> */
    public array $answered = [];

    /** @var list<array{chat_id:int|string, message_id:int, markup:array<string, mixed>|null}> */
    public array $edits = [];

    /** When true, editMessageReplyMarkup throws like Telegram does for a deleted / unchanged message. */
    public bool $failEdits = false;

    /** When true, answerCallbackQuery throws like Telegram does for an expired query ("query is too old"). */
    public bool $failAnswers = false;

    /** The next N sendMessage / sendWebAppButton calls throw like a Bot API outage (a retried job then succeeds). */
    public int $failSends = 0;

    /** What getChatMemberStatus answers (the bot's own status in the group for the admin check). */
    public string $chatMemberStatus = 'administrator';

    /** When true, getChatMemberStatus throws like a Bot API outage / 4xx. */
    public bool $failChatMember = false;

    /** @var list<array{chat_id:int|string, user_id:int}> */
    public array $chatMemberLookups = [];

    /** @var list<array{title:string, description:string, payload:string, currency:string, prices:list<array{label:string, amount:int}>, subscription_period:int|null}> */
    public array $invoiceLinks = [];

    /** @var list<array{id:string, ok:bool, error_message:string|null}> */
    public array $preCheckoutAnswers = [];

    /** @var list<array{user_id:int, charge_id:string, is_canceled:bool}> */
    public array $starSubscriptionEdits = [];

    /** @var list<array{user_id:int, charge_id:string}> */
    public array $starRefunds = [];

    /**
     * What getStarTransactions pages through (newest first), as raw `StarTransaction` arrays.
     *
     * @var list<array<string, mixed>>
     */
    public array $starTransactions = [];

    /** @var list<array{offset:int, limit:int}> */
    public array $starTransactionPages = [];

    /** When true, every Stars method (invoice link, subscription edit, refund, transactions) throws like a Bot API outage. */
    public bool $failStars = false;

    /** Called on every Stars method before it is recorded: a test hooks it to assert on the moment of the call (e.g. no open transaction). */
    public ?Closure $onStarsCall = null;

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => null, 'url' => null, 'markup' => $replyMarkup];
    }

    public function sendWebAppButton(int|string $chatId, string $text, string $buttonText, string $url, ?string $parseMode = null): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => $buttonText, 'url' => $url, 'markup' => null];
    }

    public function answerCallbackQuery(string $callbackQueryId): void
    {
        if ($this->failAnswers) {
            throw new TelegramApiException('query is too old and response timeout expired');
        }

        $this->answered[] = $callbackQueryId;
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        if ($this->failEdits) {
            throw new TelegramApiException('message is not modified');
        }

        $this->edits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'markup' => $replyMarkup];
    }

    public function getChatMemberStatus(int|string $chatId, int $userId): string
    {
        $this->chatMemberLookups[] = ['chat_id' => $chatId, 'user_id' => $userId];

        if ($this->failChatMember) {
            throw new TelegramApiException('Bad Request: chat not found');
        }

        return $this->chatMemberStatus;
    }

    public function setWebhook(string $url, string $secretHeader): bool
    {
        return true;
    }

    public function deleteWebhook(): bool
    {
        return true;
    }

    public function createInvoiceLink(string $title, string $description, string $payload, string $currency, array $prices, ?int $subscriptionPeriod = null): string
    {
        $this->starsCall('createInvoiceLink');
        $this->invoiceLinks[] = ['title' => $title, 'description' => $description, 'payload' => $payload, 'currency' => $currency, 'prices' => $prices, 'subscription_period' => $subscriptionPeriod];

        return 'https://t.me/$fake-invoice-'.count($this->invoiceLinks);
    }

    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, ?string $errorMessage = null): void
    {
        $this->starsCall('answerPreCheckoutQuery');
        $this->preCheckoutAnswers[] = ['id' => $preCheckoutQueryId, 'ok' => $ok, 'error_message' => $errorMessage];
    }

    public function editUserStarSubscription(int $userId, string $telegramPaymentChargeId, bool $isCanceled): void
    {
        $this->starsCall('editUserStarSubscription');
        $this->starSubscriptionEdits[] = ['user_id' => $userId, 'charge_id' => $telegramPaymentChargeId, 'is_canceled' => $isCanceled];
    }

    public function refundStarPayment(int $userId, string $telegramPaymentChargeId): void
    {
        $this->starsCall('refundStarPayment');
        $this->starRefunds[] = ['user_id' => $userId, 'charge_id' => $telegramPaymentChargeId];
    }

    public function getStarTransactions(int $offset = 0, int $limit = 100): array
    {
        $this->starsCall('getStarTransactions');
        $this->starTransactionPages[] = ['offset' => $offset, 'limit' => $limit];

        return array_values(array_slice($this->starTransactions, $offset, $limit));
    }

    private function starsCall(string $method): void
    {
        if ($this->onStarsCall !== null) {
            ($this->onStarsCall)($method);
        }

        if ($this->failStars) {
            throw new TelegramApiException('Bad Gateway');
        }
    }

    private function failNextSend(): void
    {
        if ($this->failSends > 0) {
            $this->failSends--;

            throw new TelegramApiException('Bad Gateway');
        }
    }

    /**
     * Callback data of every callback button of the last sent message that carried an inline keyboard.
     *
     * @return list<string>
     */
    public function lastCallbackData(): array
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            $markup = $this->sent[$i]['markup'];
            if ($markup === null) {
                continue;
            }

            $data = [];
            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && isset($button['callback_data'])) {
                        $data[] = (string) $button['callback_data'];
                    }
                }
            }

            return $data;
        }

        return [];
    }
}
