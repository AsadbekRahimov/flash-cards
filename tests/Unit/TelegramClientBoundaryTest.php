<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use GuzzleHttp\Psr7\Response;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

it('binds the Telegram client contract to the irazasyed adapter', function (): void {
    expect(app(TelegramClient::class))
        ->toBeInstanceOf(IrazasyedTelegramClient::class)
        ->toBe(app(IrazasyedTelegramClient::class));
});

it('builds a Telegram WebApp inline keyboard', function (): void {
    $markup = app(TelegramKeyboardFactory::class)->webAppButton(
        text: 'Open training',
        url: 'https://twa.test/twa/training/123',
    );

    expect($markup)->toBe([
        'inline_keyboard' => [[
            [
                'text' => 'Open training',
                'web_app' => ['url' => 'https://twa.test/twa/training/123'],
            ],
        ]],
    ]);
});

it('uses telegram bot sdk when sending a WebApp button', function (): void {
    $sdk = new class extends Api
    {
        /** @var list<array<string, mixed>> */
        public array $sentMessages = [];

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /**
         * @param  array<string, mixed>  $params
         */
        public function sendMessage(array $params): MessageObject
        {
            $this->sentMessages[] = $params;

            return new MessageObject([]);
        }
    };

    $client = new IrazasyedTelegramClient(
        $sdk,
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    );

    $client->sendWebAppButton(
        chatId: -1001,
        text: 'Training started',
        buttonText: 'Open',
        url: 'https://twa.test/twa/training/123',
        parseMode: 'HTML',
    );

    expect($sdk->sentMessages)->toBe([[
        'chat_id' => -1001,
        'text' => 'Training started',
        'parse_mode' => 'HTML',
        'reply_markup' => '{"inline_keyboard":[[{"text":"Open","web_app":{"url":"https://twa.test/twa/training/123"}}]]}',
    ]]);
});

it('removes or replaces the inline keyboard of a sent message through the SDK', function (): void {
    $sdk = new class extends Api
    {
        /** @var list<array<string, mixed>> */
        public array $edits = [];

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /**
         * @param  array<string, mixed>  $params
         */
        public function editMessageReplyMarkup(array $params): MessageObject
        {
            $this->edits[] = $params;

            return new MessageObject([]);
        }
    };

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->editMessageReplyMarkup(555, 42);
    $client->editMessageReplyMarkup(555, 43, ['inline_keyboard' => [[['text' => 'Go', 'callback_data' => 'x:y']]]]);

    expect($sdk->edits)->toBe([
        ['chat_id' => 555, 'message_id' => 42, 'reply_markup' => '{"inline_keyboard":[]}'],
        ['chat_id' => 555, 'message_id' => 43, 'reply_markup' => '{"inline_keyboard":[[{"text":"Go","callback_data":"x:y"}]]}'],
    ]);
});

it('registers the webhook with pre_checkout_query among the allowed updates', function (): void {
    $sdk = new class extends Api
    {
        /** @var array<string, mixed> */
        public array $webhook = [];

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /** @param array<string, mixed> $params */
        public function setWebhook(array $params): bool
        {
            $this->webhook = $params;

            return true;
        }
    };

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    expect($client->setWebhook('https://lexiflow.test/telegram/webhook/s', 'h'))->toBeTrue()
        ->and($sdk->webhook['allowed_updates'])->toBe(['message', 'my_chat_member', 'chat_member', 'callback_query', 'pre_checkout_query'])
        ->and($sdk->webhook['secret_token'])->toBe('h');
});

it('calls the Stars Bot API methods through the SDK: createInvoiceLink / answerPreCheckoutQuery wrappers and raw post for the rest', function (): void {
    $sdk = new class extends Api
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $calls = [];

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /** @param array<string, mixed> $params */
        public function createInvoiceLink(array $params): string
        {
            $this->calls[] = ['createInvoiceLink', $params];

            return 'https://t.me/$abc';
        }

        /** @param array<string, mixed> $params */
        public function answerPreCheckoutQuery(array $params): bool
        {
            $this->calls[] = ['answerPreCheckoutQuery', $params];

            return true;
        }

        /** @param array<string, mixed> $params */
        public function post(string $endpoint, array $params = [], bool $fileUpload = false): TelegramResponse
        {
            $this->calls[] = [$endpoint, $params];
            $body = $endpoint === 'getStarTransactions'
                ? '{"ok":true,"result":{"transactions":[{"id":"t1","amount":5,"date":1}, "junk"]}}'
                : '{"ok":true,"result":true}';

            return new TelegramResponse(new TelegramRequest('test-token', 'POST', $endpoint, $params), new Response(200, [], $body));
        }
    };

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    expect($client->createInvoiceLink('LexiFlow Pro', 'desc', 'tok', 'XTR', [['label' => 'Pro', 'amount' => 150]], 2_592_000))->toBe('https://t.me/$abc');
    $client->createInvoiceLink('Year', 'desc', 'tok2', 'XTR', [['label' => 'Pro', 'amount' => 1300]]);
    $client->answerPreCheckoutQuery('q1', true);
    $client->answerPreCheckoutQuery('q2', false, 'Nope');
    $client->editUserStarSubscription(555, 'ch_1', true);
    $client->refundStarPayment(555, 'ch_1');
    $transactions = $client->getStarTransactions(100, 500);

    expect($sdk->calls)->toBe([
        ['createInvoiceLink', ['title' => 'LexiFlow Pro', 'description' => 'desc', 'payload' => 'tok', 'currency' => 'XTR', 'prices' => [['label' => 'Pro', 'amount' => 150]], 'subscription_period' => 2_592_000]],
        ['createInvoiceLink', ['title' => 'Year', 'description' => 'desc', 'payload' => 'tok2', 'currency' => 'XTR', 'prices' => [['label' => 'Pro', 'amount' => 1300]]]],
        ['answerPreCheckoutQuery', ['pre_checkout_query_id' => 'q1', 'ok' => true]],
        ['answerPreCheckoutQuery', ['pre_checkout_query_id' => 'q2', 'ok' => false, 'error_message' => 'Nope']],
        ['editUserStarSubscription', ['user_id' => 555, 'telegram_payment_charge_id' => 'ch_1', 'is_canceled' => true]],
        ['refundStarPayment', ['user_id' => 555, 'telegram_payment_charge_id' => 'ch_1']],
        ['getStarTransactions', ['offset' => 100, 'limit' => 100]],
    ])->and($transactions)->toBe([['id' => 't1', 'amount' => 5, 'date' => 1]]);
});

it('hides the SDK failure of a Stars call behind TelegramApiException without the token', function (): void {
    $sdk = new class extends Api
    {
        public function __construct()
        {
            parent::__construct('123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
        }

        /** @param array<string, mixed> $params */
        public function post(string $endpoint, array $params = [], bool $fileUpload = false): TelegramResponse
        {
            throw new RuntimeException('cURL error 28 for https://api.telegram.org/bot123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA/refundStarPayment');
        }
    };

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    try {
        $client->refundStarPayment(1, 'c');
        $this->fail('expected TelegramApiException');
    } catch (TelegramApiException $e) {
        expect($e->getMessage())->not->toContain('AAAAAAAA')->and($e->getPrevious())->toBeNull();
    }
});
