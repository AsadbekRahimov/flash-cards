<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramForbiddenException;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Jobs\HandleTelegramUpdate;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\TelegramGroup;
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

it('processes a new_chat_members update through the queued job: learner + membership', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -4242, 'status' => 'active']);
    $channel = Mockery::mock();
    Log::shouldReceive('channel')->with('daily')->andReturn($channel);
    $channel->shouldReceive('info')->once();

    (new HandleTelegramUpdate([
        'update_id' => 78,
        'message' => [
            'chat' => ['id' => -4242, 'type' => 'supergroup'],
            'new_chat_members' => [['id' => 777, 'first_name' => 'Alexander', 'is_bot' => false]],
        ],
    ]))->handle(app(TelegramDispatcher::class));

    $learner = Learner::query()->where('telegram_user_id', 777)->sole();
    expect(GroupMember::query()->where('learner_id', $learner->id)->where('telegram_group_id', $group->id)->value('status'))
        ->toBe('active');
});

it('processes a chat_member left update through the queued job: membership becomes left', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => -4243, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 777]);
    $channel = Mockery::mock();
    Log::shouldReceive('channel')->with('daily')->andReturn($channel);
    $channel->shouldReceive('info')->once();

    (new HandleTelegramUpdate([
        'update_id' => 79,
        'chat_member' => [
            'chat' => ['id' => -4243, 'type' => 'supergroup'],
            'new_chat_member' => ['status' => 'left', 'user' => ['id' => 777, 'first_name' => 'Alexander', 'is_bot' => false]],
        ],
    ]))->handle(app(TelegramDispatcher::class));

    expect(GroupMember::query()->where('learner_id', $learner->id)->value('status'))->toBe('left');
});
