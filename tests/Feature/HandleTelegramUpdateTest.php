<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\HandleTelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function forbiddenHelpUpdate(): array
{
    return [
        'update_id' => 77,
        'message' => [
            'text' => '/help',
            'chat' => ['id' => 555, 'type' => 'private'],
            'from' => ['id' => 555, 'first_name' => 'Alexander', 'language_code' => 'ru'],
        ],
    ];
}

it('finishes without retrying when the bot is forbidden mid-command', function (): void {
    $channel = Mockery::mock();
    Log::shouldReceive('channel')->with('daily')->andReturn($channel);
    $channel->shouldReceive('info')->once();
    $channel->shouldReceive('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'telegram.handler_forbidden'
            && $context === ['reason' => 'bot_blocked', 'update_id' => 77],
    );
    $channel->shouldNotReceive('error');
    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendMessage')->once()
        ->andThrow(new TelegramForbiddenException(TelegramForbiddenException::REASON_BOT_BLOCKED));
    $this->app->instance(TelegramClient::class, $client);

    (new HandleTelegramUpdate(forbiddenHelpUpdate()))->handle(app(TelegramDispatcher::class));
});

it('still rethrows other handler failures so the job is retried', function (): void {
    $channel = Mockery::mock();
    Log::shouldReceive('channel')->with('daily')->andReturn($channel);
    $channel->shouldReceive('info')->once();
    $channel->shouldReceive('error')->once();
    $client = Mockery::mock(TelegramClient::class);
    $client->shouldReceive('sendMessage')->once()->andThrow(new RuntimeException('boom'));
    $this->app->instance(TelegramClient::class, $client);

    expect(fn () => (new HandleTelegramUpdate(forbiddenHelpUpdate()))->handle(app(TelegramDispatcher::class)))
        ->toThrow(RuntimeException::class, 'boom');
});
