<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use Illuminate\Support\Facades\Artisan;

/*
 * `php artisan telegram:set-webhook`: runs on every deploy (docker/telegram-setup.sh) and the deploy log is kept,
 * so the URL secret and the header secret must never reach the output.
 */

beforeEach(function (): void {
    config([
        'telegram.webhook_url' => 'https://bot.example.test/',
        'telegram.url_secret' => 'urlsecret-abcdef123456',
        'telegram.header_secret' => 'headersecret-987654',
    ]);
});

it('prints the host and the path of the webhook without the URL secret', function (): void {
    $code = Artisan::call('telegram:set-webhook', ['-vvv' => true]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('https://bot.example.test/telegram/webhook/***')
        ->and($output)->not->toContain('urlsecret')
        ->and($output)->not->toContain('headersecret');
});

it('does not print the secrets when the variables are missing', function (): void {
    config(['telegram.header_secret' => '']);

    $code = Artisan::call('telegram:set-webhook');

    expect($code)->toBe(1)->and(Artisan::output())->not->toContain('urlsecret');
});

it('masks the secrets in the message of a Telegram API error', function (): void {
    $this->mock(TelegramClient::class)
        ->shouldReceive('setWebhook')
        ->andThrow(new TelegramApiException('Bad Request: bad webhook: https://bot.example.test/telegram/webhook/urlsecret-abcdef123456 headersecret-987654'));

    $code = Artisan::call('telegram:set-webhook');
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('Failed to set webhook')
        ->and($output)->not->toContain('urlsecret')
        ->and($output)->not->toContain('headersecret');
});
