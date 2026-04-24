<?php

declare(strict_types=1);

use App\Jobs\HandleTelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.url_secret', 'url-secret-value-1234567890');
    Config::set('telegram.header_secret', 'header-secret-value-abcdef');
});

function postWebhook(array $server = []): TestResponse
{
    return test()->withServerVariables($server)->postJson(
        '/telegram/webhook/url-secret-value-1234567890',
        ['update_id' => 1],
        ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'],
    );
}

it('does not check IP when allowlist is disabled', function (): void {
    Config::set('telegram.ip_allowlist.enabled', false);
    Bus::fake();

    postWebhook(['REMOTE_ADDR' => '8.8.8.8'])->assertOk();

    Bus::assertDispatched(HandleTelegramUpdate::class);
});

it('allows Telegram IP ranges when allowlist is enabled', function (): void {
    Config::set('telegram.ip_allowlist.enabled', true);
    Bus::fake();

    postWebhook(['REMOTE_ADDR' => '149.154.167.200'])->assertOk();
    postWebhook(['REMOTE_ADDR' => '91.108.4.5'])->assertOk();
});

it('blocks non-Telegram IP when allowlist is enabled', function (): void {
    Config::set('telegram.ip_allowlist.enabled', true);
    Bus::fake();

    postWebhook(['REMOTE_ADDR' => '8.8.8.8'])->assertStatus(403);

    Bus::assertNotDispatched(HandleTelegramUpdate::class);
});
