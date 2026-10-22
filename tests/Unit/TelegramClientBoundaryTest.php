<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;

it('binds the Telegram client contract to the irazasyed adapter', function (): void {
    // The base TestCase swaps in a fake; this is the production wiring.
    $this->refreshApplication();

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
        chatId: 1001,
        text: 'Training started',
        buttonText: 'Open',
        url: 'https://twa.test/twa/training/123',
        parseMode: 'HTML',
    );

    expect($sdk->sentMessages)->toBe([[
        'chat_id' => 1001,
        'text' => 'Training started',
        'parse_mode' => 'HTML',
        'reply_markup' => '{"inline_keyboard":[[{"text":"Open","web_app":{"url":"https://twa.test/twa/training/123"}}]]}',
    ]]);
});

function recordingSdk(): Api
{
    return new class extends Api
    {
        /** @var list<array<string, mixed>> */
        public array $sentMessages = [];

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /** @param array<string, mixed> $params */
        public function sendMessage(array $params): MessageObject
        {
            $this->sentMessages[] = $params;

            return new MessageObject([]);
        }
    };
}

it('sends a t.me URL button instead of web_app to group chats (web_app is private-chat only)', function (int|string $chatId): void {
    config(['twa.base_url' => 'https://twa.test', 'telegram.bot_username' => '@lexi_bot', 'telegram.mini_app_short_name' => '']);
    $sdk = recordingSdk();
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendWebAppButton($chatId, 'Exam', 'Open', 'https://twa.test/twa/exam/7');

    $markup = json_decode((string) $sdk->sentMessages[0]['reply_markup'], true);
    $button = $markup['inline_keyboard'][0][0];

    expect($button)->not->toHaveKey('web_app')
        ->and($button['text'])->toBe('Open')
        ->and($button['url'])->toBe('https://t.me/lexi_bot?startapp='.rtrim(strtr(base64_encode('/exam/7'), '+/', '-_'), '='));
})->with([-1001234567890, '-1001234567890', '@my_channel']);

it('uses the Mini App short name in the group link and keeps the query string in start_param', function (): void {
    config(['twa.base_url' => 'https://twa.test', 'telegram.bot_username' => 'lexi_bot', 'telegram.mini_app_short_name' => 'app']);
    $sdk = recordingSdk();
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendWebAppButton(-5, 'Hw', 'Open', 'https://twa.test/twa/?from=assignment&lesson=12');

    $url = json_decode((string) $sdk->sentMessages[0]['reply_markup'], true)['inline_keyboard'][0][0]['url'];
    expect($url)->toStartWith('https://t.me/lexi_bot/app?startapp=');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect($query['startapp'])->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(base64_decode(strtr((string) $query['startapp'], '-_', '+/')))->toBe('/?from=assignment&lesson=12');
});

it('keeps web_app for private chats given as a numeric string', function (): void {
    $sdk = recordingSdk();
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendWebAppButton('555', 'Hi', 'Open', 'https://twa.test/twa/');

    expect((string) $sdk->sentMessages[0]['reply_markup'])->toContain('"web_app"');
});

it('falls back to a plain URL button on the TWA page when the bot username is not configured', function (): void {
    config(['twa.base_url' => 'https://twa.test', 'telegram.bot_username' => '']);
    Log::spy();
    $sdk = recordingSdk();
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendWebAppButton(-9, 'Exam', 'Open', 'https://twa.test/twa/exam/7');

    $button = json_decode((string) $sdk->sentMessages[0]['reply_markup'], true)['inline_keyboard'][0][0];
    expect($button)->toBe(['text' => 'Open', 'url' => 'https://twa.test/twa/exam/7']);
    Log::shouldHaveReceived('warning')->once();
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

it('uploads a local file as a photo with a caption and a keyboard, and maps a 403 to TelegramForbiddenException', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'card').'.png';
    file_put_contents($file, "\x89PNG\r\n\x1a\nfake");

    $sdk = new class extends Api
    {
        /** @var list<array<string, mixed>> */
        public array $photos = [];

        public bool $forbid = false;

        public function __construct()
        {
            parent::__construct('test-token');
        }

        /** @param array<string, mixed> $params */
        public function sendPhoto(array $params): MessageObject
        {
            if ($this->forbid) {
                throw new TelegramResponseException(new TelegramResponse(new TelegramRequest('t', 'POST', 'sendPhoto'), new Response(403, [], '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}')));
            }
            $this->photos[] = $params;

            return new MessageObject([]);
        }
    };

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);
    $client->sendPhoto(555, $file, 'Caption', ['inline_keyboard' => [[['text' => 'Share', 'url' => 'https://t.me/share/url?url=x']]]]);

    expect($sdk->photos)->toHaveCount(1)
        ->and($sdk->photos[0]['chat_id'])->toBe(555)
        ->and($sdk->photos[0]['caption'])->toBe('Caption')
        ->and($sdk->photos[0]['photo'])->toBeInstanceOf(InputFile::class)
        ->and($sdk->photos[0]['reply_markup'])->toBe('{"inline_keyboard":[[{"text":"Share","url":"https://t.me/share/url?url=x"}]]}');

    $sdk->forbid = true;
    expect(fn () => $client->sendPhoto(555, $file))->toThrow(TelegramForbiddenException::class);

    expect(fn () => $client->sendPhoto(555, $file.'.missing'))->toThrow(TelegramApiException::class, 'not readable');
    unlink($file);
});
