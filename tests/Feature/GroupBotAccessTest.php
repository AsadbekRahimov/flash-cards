<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramApiException;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Models\TelegramGroup;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\HttpClients\GuzzleHttpClient;
use Tests\Support\FakeTelegramClient;

uses(RefreshDatabase::class);

const GROUP_ACCESS_TOKEN = '777000111:AAEhBPsecretsecretsecretsecretsecret12';

beforeEach(function (): void {
    Config::set('telegram.bot_token', GROUP_ACCESS_TOKEN);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

/** @param array<string, mixed> $overrides */
function botAddedToGroup(int $chatId = -100777, array $overrides = []): void
{
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => array_replace_recursive([
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Class 1'],
        'from' => ['id' => 4242],
        'old_chat_member' => ['status' => 'left'],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 777000111, 'is_bot' => true]],
    ], $overrides)]);
}

it('does not warn when the bot is an administrator or the creator', function (string $status): void {
    $this->tg->chatMemberStatus = $status;

    botAddedToGroup();

    expect($this->tg->sent)->toBe([])
        ->and($this->tg->chatMemberLookups)->toBe([['chat_id' => -100777, 'user_id' => 777000111]])
        ->and(TelegramGroup::query()->where('chat_id', -100777)->value('status'))->toBe('pending');
})->with(['administrator', 'creator']);

it('warns the group in Russian when the bot is only a member, and still registers the group', function (string $status): void {
    $this->tg->chatMemberStatus = $status;

    botAddedToGroup();

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['chat_id'])->toBe(-100777)
        ->and($this->tg->sent[0]['text'])->toBe(__('bot.group.bot_not_admin', [], 'ru'))
        ->and($this->tg->sent[0]['text'])->toContain('администратором')
        ->and(TelegramGroup::query()->where('chat_id', -100777)->value('status'))->toBe('pending');
})->with(['member', 'restricted', 'left']);

it('writes the warning in the language of the group when the bot is re-added', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100778, 'status' => 'disabled', 'meta' => ['locale' => 'uz']]);
    $this->tg->chatMemberStatus = 'member';

    botAddedToGroup(-100778);

    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe(__('bot.group.bot_not_admin', [], 'uz'))
        ->and($this->tg->sent[0]['text'])->toContain('administratori');
});

it('takes the bot id from the bot token when the update does not carry it', function (): void {
    $this->tg->chatMemberStatus = 'administrator';

    botAddedToGroup(overrides: ['new_chat_member' => ['user' => null]]);

    expect($this->tg->chatMemberLookups)->toBe([['chat_id' => -100777, 'user_id' => 777000111]]);
});

it('registers the group and logs a warning without the token when the Bot API call fails', function (): void {
    Log::spy();
    $this->tg->failChatMember = true;

    botAddedToGroup();

    expect($this->tg->sent)->toBe([])
        ->and(TelegramGroup::query()->where('chat_id', -100777)->value('status'))->toBe('pending');

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'telegram.group_bot_admin_check_failed'
            && $context['chat_id'] === -100777
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), GROUP_ACCESS_TOKEN);
    });
});

it('does not fail the registration when the warning itself cannot be sent', function (): void {
    Log::spy();
    $this->tg->chatMemberStatus = 'member';
    $this->tg->failSends = 1;

    botAddedToGroup();

    expect($this->tg->sent)->toBe([])
        ->and(TelegramGroup::query()->where('chat_id', -100777)->value('status'))->toBe('pending');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message): bool => $message === 'telegram.group_bot_admin_warning_failed',
    );
});

it('does not ask again for a known group the bot did not leave, nor when it is removed', function (): void {
    TelegramGroup::factory()->create(['chat_id' => -100779, 'status' => 'active']);
    $this->tg->chatMemberStatus = 'member';

    botAddedToGroup(-100779, ['old_chat_member' => ['status' => 'administrator']]);
    botAddedToGroup(-100779, ['new_chat_member' => ['status' => 'kicked']]);

    expect($this->tg->chatMemberLookups)->toBe([])
        ->and($this->tg->sent)->toBe([]);
});

// --- the real client: getChatMember over (faked) HTTP ----------------------------------

function clientWithResponses(Response ...$responses): IrazasyedTelegramClient
{
    $http = new GuzzleHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));

    return new IrazasyedTelegramClient(
        new Api(GROUP_ACCESS_TOKEN, false, $http),
        new TelegramKeyboardFactory,
        new TelegramMessageRateLimiter,
    );
}

it('reads the status out of a getChatMember answer', function (string $status): void {
    $client = clientWithResponses(new Response(200, [], json_encode([
        'ok' => true,
        'result' => ['user' => ['id' => 777000111, 'is_bot' => true, 'first_name' => 'LexiFlow'], 'status' => $status],
    ], JSON_THROW_ON_ERROR)));

    expect($client->getChatMemberStatus(-100777, 777000111))->toBe($status);
})->with(['administrator', 'member']);

it('turns a 4xx getChatMember answer into a token-free TelegramApiException', function (): void {
    $client = clientWithResponses(new Response(400, [], json_encode([
        'ok' => false,
        'error_code' => 400,
        'description' => 'Bad Request: chat not found',
    ], JSON_THROW_ON_ERROR)));

    try {
        $client->getChatMemberStatus(-100777, 777000111);
        $this->fail('Expected a TelegramApiException');
    } catch (TelegramApiException $e) {
        expect($e->getMessage())->toContain('chat not found')
            ->and($e->getMessage())->not->toContain('AAEhBP')
            ->and($e->getPrevious())->toBeNull();
    }
});
