<?php

declare(strict_types=1);

use App\Jobs\HandleTelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('telegram.url_secret', 'url-secret-value-1234567890');
    Config::set('telegram.header_secret', 'header-secret-value-abcdef');
});

it('rejects webhook with wrong URL secret', function (): void {
    $this->postJson('/telegram/webhook/wrong-secret-with-enough-length', [])
        ->assertStatus(403);
});

it('rejects webhook with missing header secret', function (): void {
    $this->postJson('/telegram/webhook/url-secret-value-1234567890', ['update_id' => 1])
        ->assertStatus(403);
});

it('rejects webhook with wrong header secret', function (): void {
    $this->postJson(
        '/telegram/webhook/url-secret-value-1234567890',
        ['update_id' => 1],
        ['X-Telegram-Bot-Api-Secret-Token' => 'bogus'],
    )->assertStatus(403);
});

it('accepts valid webhook and dispatches the job', function (): void {
    Bus::fake();

    $this->postJson(
        '/telegram/webhook/url-secret-value-1234567890',
        ['update_id' => 42, 'message' => ['text' => '/help']],
        ['X-Telegram-Bot-Api-Secret-Token' => 'header-secret-value-abcdef'],
    )->assertOk()->assertJson(['ok' => true]);

    Bus::assertDispatched(HandleTelegramUpdate::class);
});
