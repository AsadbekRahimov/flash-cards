<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\DTO\PreparedInlineMessage;
use App\Domain\Telegram\DTO\SentMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use Closure;
use InvalidArgumentException;

/**
 * Recording TelegramClient for tests: every outgoing call is kept in `$sent`,
 * `$answered` and `$edits` so a test can assert on text, buttons and order.
 */
final class FakeTelegramClient implements TelegramClient
{
    /** @var list<array{chat_id:int|string, text:string, button:string|null, url:string|null, markup:array<string, mixed>|null}> */
    public array $sent = [];

    /** @var list<array{chat_id:int|string, path:string, caption:string|null, markup:array<string, mixed>|null, size:int}> */
    public array $photos = [];

    /** @var list<string> */
    public array $answered = [];

    /** @var array<string, array{text: string, show_alert: bool}> answers that carried a text, by callback query id */
    public array $answeredTexts = [];

    /** @var list<array{chat_id:int|string, message_id:int, markup:array<string, mixed>|null}> */
    public array $edits = [];

    /** @var list<array{chat_id:int|string, message_id:int, text:string, parse_mode:string|null, markup:array<string, mixed>|null}> editMessageText calls */
    public array $textEdits = [];

    /** When true, editMessageText throws like Telegram does for an unchanged / too old message. */
    public bool $failTextEdits = false;

    /** When true, editMessageReplyMarkup throws like Telegram does for a deleted / unchanged message. */
    public bool $failEdits = false;

    /** When true, answerCallbackQuery throws like Telegram does for an expired query ("query is too old"). */
    public bool $failAnswers = false;

    /**
     * The next N sendMessage / sendWebAppButton / sendPhoto / sendGroupMessage / sendPhotoMessage / sendTextMessage calls throw like
     * a Bot API outage (a retried job then succeeds); a failed call is not recorded and takes no message id.
     */
    public int $failSends = 0;

    /** When true, a failing send looks like a network error (no Bot API answer) instead of a 4xx refusal. */
    public bool $failSendsAsTransport = false;

    /** When set, a failing send is a Telegram 429 with this `retry_after` (wins over `failSendsAsTransport`). */
    public ?int $failSendsRetryAfter = null;

    /** When set, a failing send is a 403 / chat not found with this reason (`TelegramForbiddenException`; wins over the two above). */
    public ?string $failSendsForbidden = null;

    /**
     * Runs at the start of every send (before the `failSends` logic) with the chat id: a test acts "while the message is on its way"
     * (a poll stopped mid-broadcast, a crash between the claim and the answer) or slows the sends down.
     */
    public ?Closure $beforeSend = null;

    /** @var list<array{chat_id:int|string, text:string, parse_mode:string|null, markup:array<string, mixed>|null, thread_id:int|null, silent:bool, message_id:int}> sendGroupMessage calls */
    public array $groupMessages = [];

    /** The `message_id` the next sendGroupMessage / sendPhotoMessage / sendTextMessage answers with (then counts up). */
    public int $nextMessageId = 1001;

    /**
     * sendPhotoMessage calls (docs/35 O1). `photo` is the path, file_id or URL of the {@see PhotoSource}; `file_id` is what the call
     * answered: the same id when the photo was sent by `file_id`, else a new `fake-photo-<n>`.
     *
     * @var list<array{chat_id:int|string, kind:string, photo:string, caption:string|null, markup:array<string, mixed>|null, message_id:int, file_id:string}>
     */
    public array $photoMessages = [];

    /** @var list<array{chat_id:int|string, text:string, markup:array<string, mixed>|null, message_id:int}> sendTextMessage calls */
    public array $textMessages = [];

    /** @var list<array{chat_id:int|string, message_id:int, caption:string, markup:array<string, mixed>|null}> editMessageCaption calls */
    public array $captionEdits = [];

    /** When true, editMessageCaption throws Telegram's 400 "message is not modified" ({@see TelegramApiException::isMessageNotModified()}). */
    public bool $failCaptionEdits = false;

    /** When set (and `failCaptionEdits` is not), editMessageCaption throws a 400 refusal with this description (e.g. "message can't be edited"). */
    public ?string $failCaptionEditsWith = null;

    /** What getChatMember answers; null = `$chatMemberStatus` as a member of the chat (`is_member` from the status). */
    public ?ChatMemberInfo $chatMember = null;

    /**
     * getChatMember per Telegram user id (docs/33 cup tests): a ChatMemberInfo, or a Throwable to throw; a user not listed falls
     * through to `$chatMember` / `$chatMemberStatus`. Every call is recorded in `$chatMemberLookups`.
     *
     * @var array<int, ChatMemberInfo|\Throwable>
     */
    public array $chatMembersByUser = [];

    /** What getChatMemberStatus answers (the bot's own status in the group for the admin check). */
    public string $chatMemberStatus = 'administrator';

    /** @var list<int|string> chats that got a web-app button with `immediate: true` (never deferred) */
    public array $immediateButtons = [];

    /** When true, getChatMemberStatus throws like a Bot API outage / 4xx. */
    public bool $failChatMember = false;

    /** @var list<array{chat_id:int|string, user_id:int}> */
    public array $chatMemberLookups = [];

    /** Called on every getChatMemberStatus before the answer: a test hooks it to act "meanwhile" (a parallel write between the read and the lock). */
    public ?Closure $onChatMemberLookup = null;

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

    /** When set, editUserStarSubscription for exactly this charge id throws like a Bot API refusal. */
    public ?string $failStarsOn = null;

    /** Called on every Stars method before it is recorded: a test hooks it to assert on the moment of the call (e.g. no open transaction). */
    public ?Closure $onStarsCall = null;

    /** @var list<array{commands:list<array{command:string, description:string}>, scope:array<string, mixed>, language_code:string|null}> */
    public array $myCommands = [];

    /** @var list<array<string, mixed>> */
    public array $menuButtons = [];

    /** When true, setMyCommands throws like a Bot API refusal. */
    public bool $failMyCommands = false;

    /** When true, setChatMenuButton throws like a Bot API refusal. */
    public bool $failMenuButton = false;

    /** @var list<array{user_id:int, result:array<string, mixed>, allow_user_chats:bool, allow_bot_chats:bool, allow_group_chats:bool, allow_channel_chats:bool, id:string}> savePreparedInlineMessage calls */
    public array $preparedMessages = [];

    /** The `expiration_date` the next prepared message answers with; null = one day from now. */
    public ?int $preparedExpirationDate = null;

    /** Runs at the start of every savePreparedInlineMessage call (a race test makes the call slow with it). */
    public ?Closure $beforePrepare = null;

    /** @var list<array{inline_query_id:string, results:list<array<string, mixed>>, cache_time:int, is_personal:bool}> answerInlineQuery calls */
    public array $inlineAnswers = [];

    /** The next N savePreparedInlineMessage / answerInlineQuery calls throw like a Bot API refusal (400). */
    public int $failInline = 0;

    /** When true, a failing inline call looks like a network error (no Bot API answer) instead of a 4xx refusal. */
    public bool $failInlineAsTransport = false;

    /** When set, a failing inline call is a Telegram 429 with this `retry_after` (wins over `failInlineAsTransport`). */
    public ?int $failInlineRetryAfter = null;

    public function sendMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => null, 'url' => null, 'markup' => $replyMarkup];
    }

    public function sendGroupMessage(int|string $chatId, string $text, ?string $parseMode = null, ?array $replyMarkup = null, ?int $threadId = null, bool $disableNotification = false): int
    {
        $this->failNextSend();
        $messageId = $this->nextMessageId++;
        $this->groupMessages[] = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode,
            'markup' => $replyMarkup,
            'thread_id' => $threadId,
            'silent' => $disableNotification,
            'message_id' => $messageId,
        ];

        return $messageId;
    }

    public function sendWebAppButton(int|string $chatId, string $text, string $buttonText, string $url, ?string $parseMode = null, bool $immediate = false): void
    {
        $this->failNextSend();
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text, 'button' => $buttonText, 'url' => $url, 'markup' => null];
        if ($immediate) {
            $this->immediateButtons[] = $chatId;
        }
    }

    public function sendPhoto(int|string $chatId, string $photoPath, ?string $caption = null, ?array $replyMarkup = null): void
    {
        $this->failNextSend();
        $this->photos[] = [
            'chat_id' => $chatId,
            'path' => $photoPath,
            'caption' => $caption,
            'markup' => $replyMarkup,
            // The file may be a temporary one: keep what a test needs to assert on while it still exists.
            'size' => is_file($photoPath) ? (int) filesize($photoPath) : -1,
        ];
    }

    public function sendPhotoMessage(int|string $chatId, PhotoSource $photo, ?string $caption = null, ?array $replyMarkup = null): SentMessage
    {
        $this->failNextSend('sendPhoto', $chatId);
        $messageId = $this->nextMessageId++;
        $fileId = $photo->kind === PhotoSource::KIND_FILE_ID ? $photo->value : 'fake-photo-'.$messageId;
        $this->photoMessages[] = [
            'chat_id' => $chatId,
            'kind' => $photo->kind,
            'photo' => $photo->value,
            'caption' => $caption,
            'markup' => $replyMarkup,
            'message_id' => $messageId,
            'file_id' => $fileId,
        ];

        return new SentMessage($messageId, $fileId);
    }

    public function sendTextMessage(int|string $chatId, string $text, ?array $replyMarkup = null): SentMessage
    {
        $this->failNextSend('sendMessage', $chatId);
        $messageId = $this->nextMessageId++;
        $this->textMessages[] = ['chat_id' => $chatId, 'text' => $text, 'markup' => $replyMarkup, 'message_id' => $messageId];

        return new SentMessage($messageId);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        if ($this->failAnswers) {
            throw new TelegramApiException('query is too old and response timeout expired');
        }

        $this->answered[] = $callbackQueryId;
        if ($text !== null) {
            $this->answeredTexts[$callbackQueryId] = ['text' => $text, 'show_alert' => $showAlert];
        }
    }

    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        if ($this->failEdits) {
            throw new TelegramApiException('message is not modified');
        }

        $this->edits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'markup' => $replyMarkup];
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
    {
        if ($this->failTextEdits) {
            throw TelegramApiException::apiError('Bad Request: message is not modified', 400);
        }

        $this->textEdits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => $parseMode, 'markup' => $replyMarkup];
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, ?array $replyMarkup = null): void
    {
        if ($this->failCaptionEdits) {
            throw TelegramApiException::apiError('Bad Request: message is not modified: specified new message content and reply markup are exactly the same', 400);
        }
        if ($this->failCaptionEditsWith !== null) {
            throw TelegramApiException::apiError('Bad Request: '.$this->failCaptionEditsWith, 400);
        }

        $this->captionEdits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'caption' => $caption, 'markup' => $replyMarkup];
    }

    /** @return array{chat_id:int|string, message_id:int, text:string, parse_mode:string|null, markup:array<string, mixed>|null}|null */
    public function lastTextEdit(): ?array
    {
        return $this->textEdits === [] ? null : $this->textEdits[array_key_last($this->textEdits)];
    }

    public function getChatMemberStatus(int|string $chatId, int $userId): string
    {
        $this->chatMemberLookups[] = ['chat_id' => $chatId, 'user_id' => $userId];

        if ($this->onChatMemberLookup !== null) {
            ($this->onChatMemberLookup)($chatId, $userId);
        }

        if ($this->failChatMember) {
            throw new TelegramApiException('Bad Request: chat not found');
        }

        return $this->chatMemberStatus;
    }

    public function getChatMember(int|string $chatId, int $userId): ChatMemberInfo
    {
        if (array_key_exists($userId, $this->chatMembersByUser)) {
            $this->chatMemberLookups[] = ['chat_id' => $chatId, 'user_id' => $userId];
            $answer = $this->chatMembersByUser[$userId];
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }

        if ($this->chatMember !== null) {
            $this->chatMemberLookups[] = ['chat_id' => $chatId, 'user_id' => $userId];

            return $this->chatMember;
        }

        return ChatMemberInfo::fromArray(['status' => $this->getChatMemberStatus($chatId, $userId), 'is_member' => true]);
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
        if ($this->failStarsOn !== null && $this->failStarsOn === $telegramPaymentChargeId) {
            throw new TelegramApiException('Bad Request');
        }
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

    public function setMyCommands(array $commands, array $scope, ?string $languageCode = null): bool
    {
        if ($this->failMyCommands) {
            throw new TelegramApiException('Bad Request: invalid commands');
        }

        $this->myCommands[] = ['commands' => $commands, 'scope' => $scope, 'language_code' => $languageCode];

        return true;
    }

    public function setChatMenuButton(array $menuButton): bool
    {
        if ($this->failMenuButton) {
            throw new TelegramApiException('Bad Request: invalid menu button');
        }

        $this->menuButtons[] = $menuButton;

        return true;
    }

    public function savePreparedInlineMessage(int $userId, array $result, bool $allowUserChats = false, bool $allowBotChats = false, bool $allowGroupChats = false, bool $allowChannelChats = false): PreparedInlineMessage
    {
        if ($this->beforePrepare !== null) {
            ($this->beforePrepare)();
        }
        $this->failNextInline('savePreparedInlineMessage');

        $id = 'prepared-'.(count($this->preparedMessages) + 1);
        $this->preparedMessages[] = [
            'user_id' => $userId,
            'result' => $result,
            'allow_user_chats' => $allowUserChats,
            'allow_bot_chats' => $allowBotChats,
            'allow_group_chats' => $allowGroupChats,
            'allow_channel_chats' => $allowChannelChats,
            'id' => $id,
        ];

        return new PreparedInlineMessage($id, $this->preparedExpirationDate ?? now()->addDay()->getTimestamp());
    }

    public function answerInlineQuery(string $inlineQueryId, array $results, int $cacheTime = 300, bool $isPersonal = false): void
    {
        if (count($results) > 50) {
            throw new InvalidArgumentException('answerInlineQuery takes at most 50 results.');
        }

        $this->failNextInline('answerInlineQuery');
        $this->inlineAnswers[] = ['inline_query_id' => $inlineQueryId, 'results' => $results, 'cache_time' => $cacheTime, 'is_personal' => $isPersonal];
    }

    private function failNextInline(string $method): void
    {
        if ($this->failInline > 0) {
            $this->failInline--;

            throw match (true) {
                $this->failInlineRetryAfter !== null => new TelegramRateLimitedException($this->failInlineRetryAfter, $method),
                $this->failInlineAsTransport => new TelegramApiException('cURL error 28: Operation timed out'),
                default => TelegramApiException::apiError('Bad Request: query is too old and response timeout expired or query ID is invalid', 400),
            };
        }
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

    private function failNextSend(string $method = 'sendMessage', int|string|null $chatId = null): void
    {
        if ($this->beforeSend !== null) {
            ($this->beforeSend)($chatId, $method);
        }

        if ($this->failSends > 0) {
            $this->failSends--;

            throw match (true) {
                $this->failSendsForbidden !== null => new TelegramForbiddenException($this->failSendsForbidden),
                $this->failSendsRetryAfter !== null => new TelegramRateLimitedException($this->failSendsRetryAfter, $method),
                $this->failSendsAsTransport => new TelegramApiException('cURL error 28: Operation timed out'),
                default => TelegramApiException::apiError('Forbidden: bot was kicked from the group chat', 403),
            };
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
