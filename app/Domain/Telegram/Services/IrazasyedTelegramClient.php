<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\DTO\ChatMemberInfo;
use App\Domain\Telegram\DTO\PhotoSource;
use App\Domain\Telegram\DTO\PreparedInlineMessage;
use App\Domain\Telegram\DTO\SentMessage;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Exceptions\TelegramRateLimitedException;
use App\Jobs\SendTelegramMessageJob;
use Closure;
use Exception;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\FileUpload\InputFile;
use Throwable;

class IrazasyedTelegramClient implements TelegramClient
{
    /** @var list<string> */
    public const ALLOWED_UPDATES = ['message', 'my_chat_member', 'chat_member', 'callback_query', 'pre_checkout_query'];

    public function __construct(
        private readonly Api $api,
        private readonly TelegramKeyboardFactory $keyboards,
        private readonly TelegramMessageRateLimiter $rateLimiter,
        private readonly MiniAppLinks $miniApp = new MiniAppLinks,
        // Null in production: resolved when needed, so the Octane-warm singleton never holds request-scoped services.
        private readonly ?GroupChatMigrator $migrator = null,
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
     * A group that became a supergroup is followed: the group row moves to the new chat id and the message goes there, once.
     *
     * @throws TelegramForbiddenException when Telegram answers 403 (or 400 "chat not found")
     * @throws TelegramRateLimitedException when Telegram answers 429 (the outbound budget is paused already)
     * @throws TelegramApiException on any other failed Bot API call
     */
    public function sendImmediately(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
    ): void {
        $this->followingMigration($chatId, function (int|string $chat) use ($text, $parseMode, $replyMarkup): void {
            $this->sendMessageTo($chat, $text, $parseMode, $replyMarkup);
        });
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function sendMessageTo(int|string $chatId, string $text, ?string $parseMode, ?array $replyMarkup): void
    {
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

        $this->call(fn () => $this->api->sendMessage($params), mapForbidden: true, chatId: $chatId, method: 'sendMessage');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendGroupMessage(
        int|string $chatId,
        string $text,
        ?string $parseMode = null,
        ?array $replyMarkup = null,
        ?int $threadId = null,
        bool $disableNotification = false,
    ): int {
        return $this->followingMigration($chatId, function (int|string $chat) use ($text, $parseMode, $replyMarkup, $threadId, $disableNotification): int {
            $params = ['chat_id' => $chat, 'text' => $text];

            if ($parseMode !== null) {
                $params['parse_mode'] = $parseMode;
            }
            if ($replyMarkup !== null) {
                $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            if ($threadId !== null) {
                $params['message_thread_id'] = $threadId;
            }
            if ($disableNotification) {
                $params['disable_notification'] = true;
            }

            $message = $this->call(fn () => $this->api->sendMessage($params), mapForbidden: true, chatId: $chat, method: 'sendMessage');
            $messageId = $this->resultOf($message->toArray())['message_id'] ?? null;

            if (! is_int($messageId) || $messageId <= 0) {
                // Telegram said ok but the id is missing: the message may well be in the chat. Not an API refusal (no retry posts twice).
                throw new TelegramApiException('Telegram API request failed (sendMessage): the answer carried no message_id');
            }

            return $messageId;
        });
    }

    /**
     * `web_app` buttons are allowed by the Bot API only in a private chat; anywhere else (groups,
     * supergroups, channels) the button is a plain URL to the Mini App direct link.
     */
    public function sendWebAppButton(
        int|string $chatId,
        string $text,
        string $buttonText,
        string $url,
        ?string $parseMode = null,
        bool $immediate = false,
    ): void {
        $replyMarkup = $this->miniApp->isPrivateChat($chatId)
            ? $this->keyboards->webAppButton($buttonText, $url)
            : $this->keyboards->urlButton($buttonText, $this->groupLink($url));

        // `$immediate`: the caller must see the Bot API refusal itself, not a deferred queue job's.
        $immediate
            ? $this->sendImmediately($chatId, $text, $parseMode, $replyMarkup)
            : $this->sendMessage($chatId, $text, $parseMode, $replyMarkup);
    }

    private function groupLink(string $twaUrl): string
    {
        $link = $this->miniApp->forPath($twaUrl);
        if ($link !== null) {
            return $link;
        }

        // No TELEGRAM_BOT_USERNAME (or an URL outside the TWA): the plain page still opens in a browser.
        Log::warning('telegram.mini_app_link_unavailable', ['reason' => 'bot_username_missing_or_url_not_in_twa']);

        return $twaUrl;
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

        $this->followingMigration($chatId, function (int|string $chat) use ($photoPath, $caption, $replyMarkup): void {
            $params = [
                'chat_id' => $chat,
                'photo' => InputFile::create($photoPath, basename($photoPath)),
            ];

            if ($caption !== null) {
                $params['caption'] = $caption;
            }

            if ($replyMarkup !== null) {
                $params['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            $this->call(fn () => $this->api->sendPhoto($params), mapForbidden: true, chatId: $chat, method: 'sendPhoto');
        });
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendPhotoMessage(
        int|string $chatId,
        PhotoSource $photo,
        ?string $caption = null,
        ?array $replyMarkup = null,
    ): SentMessage {
        if ($photo->isUpload() && (! is_file($photo->value) || ! is_readable($photo->value))) {
            throw new TelegramApiException('Telegram API request failed (sendPhoto): the photo file is not readable');
        }

        return $this->followingMigration($chatId, function (int|string $chat) use ($photo, $caption, $replyMarkup): SentMessage {
            $fields = ['chat_id' => $chat];
            if ($caption !== null) {
                $fields['caption'] = $caption;
            }
            if ($replyMarkup !== null) {
                $fields['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }

            // Raw `post()`, not the SDK's `sendPhoto()`: that one guesses "URL / file_id / path" from the string itself. A file goes
            // as multipart/form-data (a stream opened per attempt, so the repeat into a migrated chat uploads it again), the two
            // other kinds as a plain form field.
            $answer = $this->call(function () use ($photo, $fields): mixed {
                if (! $photo->isUpload()) {
                    return $this->api->post('sendPhoto', ['photo' => $photo->value] + $fields)->getResult();
                }

                $multipart = [['name' => 'photo', 'contents' => new LazyOpenStream($photo->value, 'r'), 'filename' => basename($photo->value)]];
                foreach ($fields as $name => $value) {
                    $multipart[] = ['name' => $name, 'contents' => (string) $value];
                }

                return $this->api->post('sendPhoto', $multipart, true)->getResult();
            }, mapForbidden: true, chatId: $chat, method: 'sendPhoto');

            return SentMessage::fromMessage(is_array($answer) ? $answer : [], 'sendPhoto');
        });
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function sendTextMessage(int|string $chatId, string $text, ?array $replyMarkup = null): SentMessage
    {
        // The immediate send with the id already exists (K1); "group" in its name is history - any chat id works.
        return new SentMessage($this->sendGroupMessage($chatId, $text, null, $replyMarkup));
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && $text !== '') {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }

        $this->call(fn () => $this->api->answerCallbackQuery($params), method: 'answerCallbackQuery');
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageReplyMarkup(int|string $chatId, int $messageId, ?array $replyMarkup = null): void
    {
        $this->notingMigration($chatId, fn () => $this->call(fn () => $this->api->editMessageReplyMarkup([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            // An empty `inline_keyboard` is how the Bot API removes the buttons.
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]), chatId: $chatId, method: 'editMessageReplyMarkup'));
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, ?string $parseMode = null, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            // Without a `reply_markup` the Bot API removes the keyboard; an empty one says it explicitly.
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }

        // No `mapForbidden`: a refusal here is "the message cannot be edited", never "the learner is unreachable".
        $this->notingMigration($chatId, fn () => $this->call(fn () => $this->api->editMessageText($params), chatId: $chatId, method: 'editMessageText'));
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, ?array $replyMarkup = null): void
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
            // As in editMessageText: an empty keyboard removes the buttons explicitly.
            'reply_markup' => json_encode($replyMarkup ?? ['inline_keyboard' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        // No `mapForbidden`: a refusal of an edit never says the learner is unreachable.
        $this->notingMigration($chatId, fn () => $this->call(fn () => $this->api->post('editMessageCaption', $params)->getResult(), chatId: $chatId, method: 'editMessageCaption'));
    }

    public function getChatMemberStatus(int|string $chatId, int $userId): string
    {
        return $this->getChatMember($chatId, $userId)->status;
    }

    public function getChatMember(int|string $chatId, int $userId): ChatMemberInfo
    {
        $member = $this->followingMigration(
            $chatId,
            fn (int|string $chat) => $this->call(fn () => $this->api->getChatMember(['chat_id' => $chat, 'user_id' => $userId]), chatId: $chat, method: 'getChatMember'),
        );

        return ChatMemberInfo::fromArray($this->resultOf($member->toArray()));
    }

    /**
     * The SDK wraps the whole response body (`{ok, result: {…}}`) into the object it returns; a bare result is taken as is.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function resultOf(array $data): array
    {
        /** @var array<string, mixed> */
        return is_array($data['result'] ?? null) ? $data['result'] : $data;
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
     * @param  list<array{command: string, description: string}>  $commands
     * @param  array<string, mixed>  $scope
     */
    public function setMyCommands(array $commands, array $scope, ?string $languageCode = null): bool
    {
        // `post()` sends a form and json-encodes `reply_markup` only: the Bot API objects are encoded here.
        return (bool) $this->call(function () use ($commands, $scope, $languageCode): mixed {
            $params = [
                'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'scope' => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];

            if ($languageCode !== null) {
                $params['language_code'] = $languageCode;
            }

            return $this->api->post('setMyCommands', $params)->getResult();
        });
    }

    /**
     * @param  array<string, mixed>  $menuButton
     */
    public function setChatMenuButton(array $menuButton): bool
    {
        // No `chat_id`: the default button of every private chat.
        return (bool) $this->call(fn (): mixed => $this->api->post('setChatMenuButton', [
            'menu_button' => json_encode($menuButton, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ])->getResult());
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function savePreparedInlineMessage(
        int $userId,
        array $result,
        bool $allowUserChats = false,
        bool $allowBotChats = false,
        bool $allowGroupChats = false,
        bool $allowChannelChats = false,
    ): PreparedInlineMessage {
        // Not wrapped by the SDK (3.x). `post()` sends a form: the result object travels as a JSON string; a flag only when true
        // (Telegram's default for every `allow_*` is false).
        $params = [
            'user_id' => $userId,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];
        foreach ([
            'allow_user_chats' => $allowUserChats,
            'allow_bot_chats' => $allowBotChats,
            'allow_group_chats' => $allowGroupChats,
            'allow_channel_chats' => $allowChannelChats,
        ] as $flag => $allowed) {
            if ($allowed) {
                $params[$flag] = true;
            }
        }

        $answer = $this->call(fn (): mixed => $this->api->post('savePreparedInlineMessage', $params)->getResult(), method: 'savePreparedInlineMessage');

        /** @var array<string, mixed> $message */
        $message = is_array($answer) ? $answer : [];

        return PreparedInlineMessage::fromArray($message);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function answerInlineQuery(string $inlineQueryId, array $results, int $cacheTime = 300, bool $isPersonal = false): void
    {
        if (count($results) > 50) {
            throw new InvalidArgumentException('answerInlineQuery takes at most 50 results.');
        }

        $params = [
            'inline_query_id' => $inlineQueryId,
            'results' => json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'cache_time' => max(0, $cacheTime),
        ];
        if ($isPersonal) {
            $params['is_personal'] = true;
        }

        $this->call(fn (): mixed => $this->api->post('answerInlineQuery', $params)->getResult(), method: 'answerInlineQuery');
    }

    /**
     * Runs a chat-bound call; when Telegram says the group became a supergroup, moves the group row to the new chat id
     * ({@see GroupChatMigrator}) and runs the call ONCE more against the new chat. The first call was refused (400), so the repeat
     * cannot deliver twice. The repeat does not depend on the database: a failed move is reported and the message still goes out.
     * A second migration answer is rethrown.
     *
     * @template T
     *
     * @param  Closure(int|string): T  $call
     * @return T
     */
    private function followingMigration(int|string $chatId, Closure $call): mixed
    {
        try {
            return $call($chatId);
        } catch (TelegramChatMigratedException $e) {
            $this->recordMigration($chatId, $e->newChatId);

            return $call($e->newChatId);
        }
    }

    /**
     * An edit in a migrated group cannot be repeated (message ids of the old chat do not exist in the new one): the group row
     * is moved, the refusal goes to the caller.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function notingMigration(int|string $chatId, Closure $call): mixed
    {
        try {
            return $call();
        } catch (TelegramChatMigratedException $e) {
            $this->recordMigration($chatId, $e->newChatId);

            throw $e;
        }
    }

    private function recordMigration(int|string $fromChatId, int $toChatId): void
    {
        if (! is_numeric($fromChatId)) {
            return; // `@channelusername`: never a group row.
        }

        try {
            ($this->migrator ?? app(GroupChatMigrator::class))->migrate((int) $fromChatId, $toChatId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Single exit point for SDK calls: nothing from the SDK/Guzzle leaves this
     * class, because their messages contain the bot token (see
     * {@see TelegramApiException}).
     *
     * Classification (docs/34 §4в п. 1), checked in this order:
     *  - 429 on ANY method → {@see TelegramRateLimitedException} (`parameters.retry_after`); `$chatId` is paused first, and the
     *    whole bot too unless `$chatId` is a group ({@see TelegramMessageRateLimiter::pause()});
     *  - 400 with `parameters.migrate_to_chat_id` → {@see TelegramChatMigratedException};
     *  - with `$mapForbidden` (sends): 403, or 400 "chat not found" → {@see TelegramForbiddenException};
     *  - anything else → {@see TelegramApiException} (`isApiResponse()` = Telegram answered 4xx).
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws TelegramForbiddenException
     * @throws TelegramRateLimitedException
     * @throws TelegramChatMigratedException
     * @throws TelegramApiException
     */
    private function call(Closure $call, bool $mapForbidden = false, int|string|null $chatId = null, string $method = 'request'): mixed
    {
        try {
            return $call();
        } catch (TelegramResponseException $e) {
            $status = $this->statusOf($e);
            $parameters = $e->getResponseData()['parameters'] ?? null;
            $parameters = is_array($parameters) ? $parameters : [];

            if ($status === 429) {
                $retryAfter = is_numeric($parameters['retry_after'] ?? null)
                    ? max(1, (int) $parameters['retry_after'])
                    : TelegramRateLimitedException::DEFAULT_RETRY_AFTER;

                $this->pauseOutbound($retryAfter, $chatId, $method);

                throw new TelegramRateLimitedException($retryAfter, $method);
            }

            if ($status === 400 && is_numeric($parameters['migrate_to_chat_id'] ?? null)) {
                throw new TelegramChatMigratedException((int) $parameters['migrate_to_chat_id'], $method);
            }

            // Keep the SDK behind the TelegramClient boundary: callers only need to
            // know that the recipient is unreachable (blocked bot / no dialog).
            if ($mapForbidden && $status === 403) {
                throw TelegramForbiddenException::fromDescription($e->getMessage());
            }

            // A deleted account answers 400 "chat not found", which is just as final as a 403.
            if ($mapForbidden
                && $status === 400
                && str_contains(strtolower($e->getMessage()), 'chat not found')
            ) {
                throw TelegramForbiddenException::fromDescription($e->getMessage());
            }

            throw TelegramApiException::fromSdk($e);
        } catch (Exception $e) {
            throw TelegramApiException::fromSdk($e);
        }
    }

    /** The Bot API `error_code` when there is one (it is what Telegram documents), else the HTTP status. */
    private function statusOf(TelegramResponseException $e): int
    {
        $code = $e->getCode();

        return $code > 0 ? $code : ($e->getHttpStatusCode() ?? 0);
    }

    /** A cache that cannot be reached must not hide the 429 itself: the pause is best effort, the exception is not. */
    private function pauseOutbound(int $retryAfter, int|string|null $chatId, string $method): void
    {
        try {
            $this->rateLimiter->pause($retryAfter, $chatId);
        } catch (Throwable $e) {
            Log::warning('telegram.rate_limit_pause_failed', ['method' => $method, 'exception' => $e::class]);
        }

        Log::warning('telegram.rate_limited', ['method' => $method, 'retry_after' => $retryAfter]);
    }
}
