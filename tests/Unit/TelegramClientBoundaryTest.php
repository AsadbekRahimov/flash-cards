<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message as MessageObject;

it('binds the Telegram client contract to the irazasyed adapter', function (): void {
    expect(app(TelegramClient::class))->toBeInstanceOf(IrazasyedTelegramClient::class);
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

    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory);

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
