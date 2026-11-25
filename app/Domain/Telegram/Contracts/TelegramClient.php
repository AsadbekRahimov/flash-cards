<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Contracts;

use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;

interface TelegramClient
{
    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void;

    /**
     * Sends a message to a group chat AT ONCE and returns its `message_id` (docs/33 §6.4, K1): a message that is edited later (the
     * card of a cup) needs the id. Unlike {@see sendMessage()} it never defers into the queue - the calling job takes the slots of
     * the per-chat and bot-wide budgets itself and puts itself back. `$threadId` is the topic of a forum group
     * (`message_thread_id`), `$disableNotification` sends it silently. A group that became a supergroup is followed, as by every send.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramForbiddenException when the bot cannot write to the chat (removed from the group, chat not found)
     * @throws TelegramApiException on any other failed Bot API call ({@see TelegramRateLimitedException} for a 429); also when
     *                              Telegram's answer carried no `message_id` (as a transport failure: it may have been posted)
     */
    public function sendGroupMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
        ?int $threadId = null,
        bool $disableNotification = false,
    ): int;

    public function sendWebAppButton(
        int|string $chatId,
        string $text,
        string $buttonText,
        string $url,
        ?string $parseMode = null,
        bool $immediate = false,
    ): void;

    /**
     * Sends a local PNG / JPEG file as a photo with an optional caption (docs/13 §7.4). Sent at once:
     * callers (queued DM jobs) take a slot of the bot-wide budget themselves.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramForbiddenException when the recipient cannot be reached (blocked bot / no dialog)
     * @throws TelegramApiException on any other failed Bot API call (or an unreadable file)
     */
    public function sendPhoto(
        int|string $chatId,
        string $photoPath,
        ?string $caption = null,
        ?array $replyMarkup = null,
    ): void;

    /**
     * Answers a tapped inline button. Without a text it only dismisses the spinner; with one the tapper alone sees it
     * (a toast, or a modal alert with `$showAlert`) and nothing is posted to the chat. Telegram cuts the text at 200 characters.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void;

    /**
     * Replaces the inline keyboard of an already sent message; `null` removes it.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramApiException when Telegram refuses (message deleted / unchanged)
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void;

    /**
     * Replaces the text (and the inline keyboard) of an already sent message; plain text unless `$parseMode` is given. The keyboard
     * goes away when `$replyMarkup` is null.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramApiException when Telegram refuses (message deleted / too old to edit / 400 "message is not modified" -
     *                              {@see TelegramApiException::isMessageNotModified()}, which a caller may count as success)
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void;

    /**
     * Status of a user in a chat (`creator`, `administrator`, `member`, `restricted`, `left`, `kicked`).
     *
     * @throws TelegramApiException when Telegram refuses or cannot be reached
     */
    public function getChatMemberStatus(int|string $chatId, int $userId): string;

    /**
     * A user's status in a chat together with `is_member` (docs/33 §4.2, K1): the one answer that tells a muted user still in the
     * chat (`restricted`, `is_member = true`) from one who left it (`restricted`, `is_member = false`).
     *
     * @throws TelegramApiException when Telegram refuses or cannot be reached
     */
    public function getChatMember(int|string $chatId, int $userId): ChatMemberInfo;

    public function setWebhook(string $url, string $secretHeader): bool;

    public function deleteWebhook(): bool;

    /**
     * Telegram Stars invoice link (`createInvoiceLink`, docs/13 §4.1). `$prices` is exactly one
     * `{label, amount}` for Stars; `$subscriptionPeriod` (seconds, only 2592000 is accepted by
     * Telegram today) turns the invoice into a subscription.
     *
     * @param  list<array{label: string, amount: int}>  $prices
     * @return string the `https://t.me/$...` link the client opens with `openInvoice`
     *
     * @throws TelegramApiException
     */
    public function createInvoiceLink(string $title, string $description, string $payload, string $currency, array $prices, ?int $subscriptionPeriod = null): string;

    /**
     * Answers a `pre_checkout_query` within Telegram's 10-second window; `$errorMessage` is shown
     * to the user when `$ok` is false.
     *
     * @throws TelegramApiException
     */
    public function answerPreCheckoutQuery(string $preCheckoutQueryId, bool $ok, ?string $errorMessage = null): void;

    /**
     * Stops (`$isCanceled = true`) or resumes a Stars subscription identified by the
     * `telegram_payment_charge_id` of its first payment.
     *
     * @throws TelegramApiException
     */
    public function editUserStarSubscription(int $userId, string $telegramPaymentChargeId, bool $isCanceled): void;

    /**
     * Returns the Stars of one payment to the user (`refundStarPayment`).
     *
     * @throws TelegramApiException
     */
    public function refundStarPayment(int $userId, string $telegramPaymentChargeId): void;

    /**
     * A page of the bot's Stars transactions (`getStarTransactions`), newest first.
     *
     * @return list<array<string, mixed>> raw `StarTransaction` objects
     *
     * @throws TelegramApiException
     */
    public function getStarTransactions(int $offset = 0, int $limit = 100): array;

    /**
     * The list of the bot's commands Telegram shows behind "/" (`setMyCommands`) for one scope and language.
     * `$scope` is a BotCommandScope object (`['type' => 'all_private_chats']`); without `$languageCode` the list
     * is the default one — what users get whose language has no list of its own.
     *
     * @param  list<array{command: string, description: string}>  $commands
     * @param  array<string, mixed>  $scope
     *
     * @throws TelegramApiException
     */
    public function setMyCommands(array $commands, array $scope, ?string $languageCode = null): bool;

    /**
     * The menu button of the bot's private chats (`setChatMenuButton` without `chat_id`: the default one for
     * every private chat), e.g. `['type' => 'web_app', 'text' => 'Muhrla', 'web_app' => ['url' => 'https://…']]`.
     *
     * @param  array<string, mixed>  $menuButton  a MenuButton object
     *
     * @throws TelegramApiException
     */
    public function setChatMenuButton(array $menuButton): bool;
}
