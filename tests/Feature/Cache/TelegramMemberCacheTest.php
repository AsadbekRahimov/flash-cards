<?php

declare(strict_types=1);

use App\Domain\Operations\Support\PureCache;
use App\Domain\Organizations\Exceptions\OrgGroupLinkException;
use App\Domain\Organizations\Services\OrgGroupLinkService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\ChatMemberStatusCache;
use App\Domain\Telegram\Services\GroupBotAccessChecker;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeTelegramClient;

/*
 * `getChatMember` for "is the bot an administrator of this group" (docs/19 K7): remembered for ten minutes, forgotten by the
 * `my_chat_member` update that changes it, never remembered for a person (the owner's right to link a group, a member of the
 * group); and never a dependency: with the pure cache down the updates are handled as if it did not exist.
 *
 * Where it is asked: once when the bot is added (MyChatMemberHandler) and once more when a center links the group
 * (OrgGroupLinkService) - not on every update, so the saving is the second call of that pair and of any later check.
 */

uses(RefreshDatabase::class);

const MEMBER_CACHE_TOKEN = '777000111:AAEhBPsecretsecretsecretsecretsecret12';
const MEMBER_CACHE_BOT = 777000111;
const MEMBER_CACHE_CHAT = -100777;

beforeEach(function (): void {
    config(['telegram.bot_token' => MEMBER_CACHE_TOKEN]);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->org = Organization::factory()->create();
    $this->owner = User::factory()->create(['telegram_user_id' => 4242, 'is_active' => true]);
});

/** A `my_chat_member` update about the bot in the group: `$old` -> `$new`. */
function memberCacheBotUpdate(string $old, string $new, int $chatId = MEMBER_CACHE_CHAT): void
{
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Class 1'],
        'from' => ['id' => 4242],
        'old_chat_member' => ['status' => $old],
        'new_chat_member' => ['status' => $new, 'user' => ['id' => MEMBER_CACHE_BOT, 'is_bot' => true]],
    ]]);
}

/** A pure store that is down: every call throws, as the connection to `redis-cache` would. */
function memberCacheDownStore(): void
{
    $store = Mockery::mock(Repository::class);
    foreach (['get', 'put', 'forget', 'has', 'many', 'putMany', 'deleteMultiple', 'flexible', 'remember', 'add', 'increment'] as $method) {
        $store->shouldReceive($method)->andThrow(new RuntimeException('redis-cache is down'));
    }

    app()->instance(PureCache::CONTAINER_KEY, $store);
}

/** The check of the bot's rights, as the link of a center runs it. */
function memberCacheBotCheck(): bool
{
    return app(GroupBotAccessChecker::class)->warnIfNotAdmin(TelegramGroup::query()->where('chat_id', MEMBER_CACHE_CHAT)->firstOrFail());
}

/** Lookups of the bot's own status. */
function memberCacheBotLookups(FakeTelegramClient $tg): int
{
    return count(array_filter($tg->chatMemberLookups, fn (array $l): bool => $l['user_id'] === MEMBER_CACHE_BOT));
}

it('asks Telegram once for the bot when it is added and the center links the group, and once for the owner', function (): void {
    $this->tg->chatMemberStatus = 'administrator';
    memberCacheBotUpdate('left', 'member');
    expect(memberCacheBotLookups($this->tg))->toBe(1);

    $group = app(OrgGroupLinkService::class)->link($this->org, MEMBER_CACHE_CHAT, $this->owner);

    expect($group->organization_id)->toBe($this->org->id)
        ->and($this->tg->chatMemberLookups)->toHaveCount(2, 'the bot at the add, the owner at the link - the bot check of the link came from the cache')
        ->and(memberCacheBotLookups($this->tg))->toBe(1);
});

it('keeps the status of the bot under the key of the group and the bot, for ten minutes', function (): void {
    config(['cache.pure_store' => 'array']); // the clock of the test moves the clock of an array store only
    memberCacheBotUpdate('left', 'member');
    $key = ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, MEMBER_CACHE_BOT);

    expect($key)->toBe('tg:member:v1:-100777:777000111')
        ->and(PureCache::store()->get($key))->toBe('administrator');

    expect(memberCacheBotLookups($this->tg))->toBe(1);
    $this->travel(ChatMemberStatusCache::TTL - 5)->seconds();
    memberCacheBotCheck();
    expect(memberCacheBotLookups($this->tg))->toBe(1, 'inside the ten minutes: from the cache');

    $this->travel(10)->seconds();
    memberCacheBotCheck();
    expect(memberCacheBotLookups($this->tg))->toBe(2, 'past it: Telegram is asked again');
});

it('knows a promotion to administrator at the next check, so a stale "member" never sends a false warning', function (): void {
    // Added as a plain member: the warning goes out and the status "member" is remembered.
    $this->tg->chatMemberStatus = 'member';
    memberCacheBotUpdate('left', 'member');
    expect($this->tg->sent)->toHaveCount(1);

    // The teacher makes the bot an administrator: Telegram says so, the handler drops the remembered status.
    $this->tg->chatMemberStatus = 'administrator';
    memberCacheBotUpdate('member', 'administrator');
    expect(PureCache::store()->has(ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, MEMBER_CACHE_BOT)))->toBeFalse();

    // The center links the group afterwards: the check sees an administrator and warns nobody.
    app(OrgGroupLinkService::class)->link($this->org, MEMBER_CACHE_CHAT, $this->owner);

    expect($this->tg->sent)->toHaveCount(1, 'only the first warning')
        ->and(memberCacheBotLookups($this->tg))->toBe(2)
        ->and(PureCache::store()->get(ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, MEMBER_CACHE_BOT)))->toBe('administrator');
});

it('knows a demotion at the next check and warns then', function (): void {
    $this->tg->chatMemberStatus = 'administrator';
    memberCacheBotUpdate('left', 'administrator');
    expect($this->tg->sent)->toBe([]);

    $this->tg->chatMemberStatus = 'member';
    memberCacheBotUpdate('administrator', 'member');
    // (The Fake answers one status for everybody, so the check is asked directly, not through the owner's link.)
    memberCacheBotCheck();

    // Only the check warns (a demotion is not an "added" event): had the administrator status stayed in the cache, nobody would be told.
    expect($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe(__('bot.group.bot_not_admin', [], 'ru'));
});

it('drops the status when the bot is removed from the group, and when the update names no bot id', function (): void {
    memberCacheBotUpdate('left', 'member');
    $key = ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, MEMBER_CACHE_BOT);
    expect(PureCache::store()->has($key))->toBeTrue();

    memberCacheBotUpdate('member', 'kicked');
    expect(PureCache::store()->has($key))->toBeFalse();

    // The id of the bot is taken from the token when the update does not carry it.
    memberCacheBotUpdate('kicked', 'member');
    expect(PureCache::store()->has($key))->toBeTrue();
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => MEMBER_CACHE_CHAT, 'type' => 'supergroup', 'title' => 'Class 1'],
        'old_chat_member' => ['status' => 'member'],
        'new_chat_member' => ['status' => 'administrator'],
    ]]);
    expect(PureCache::store()->has($key))->toBeFalse();
});

it('leaves the status of another group alone', function (): void {
    memberCacheBotUpdate('left', 'member', -100501);
    memberCacheBotUpdate('left', 'member', -100502);
    memberCacheBotUpdate('member', 'administrator', -100501);

    expect(PureCache::store()->has(ChatMemberStatusCache::key(-100501, MEMBER_CACHE_BOT)))->toBeFalse()
        ->and(PureCache::store()->has(ChatMemberStatusCache::key(-100502, MEMBER_CACHE_BOT)))->toBeTrue();
});

it('does not remember a failed call: the group is asked again', function (): void {
    Log::spy();
    $this->tg->failChatMember = true;
    memberCacheBotUpdate('left', 'member');

    $key = ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, MEMBER_CACHE_BOT);
    expect(PureCache::store()->has($key))->toBeFalse()
        ->and($this->tg->sent)->toBe([]);

    $this->tg->failChatMember = false;
    $this->tg->chatMemberStatus = 'administrator';
    memberCacheBotCheck();

    expect(PureCache::store()->get($key))->toBe('administrator')
        ->and(memberCacheBotLookups($this->tg))->toBe(2);
});

it('never remembers whether the OWNER is an administrator: that decides who may link a group', function (): void {
    $this->tg->chatMemberStatus = 'administrator';
    memberCacheBotUpdate('left', 'member');
    $service = app(OrgGroupLinkService::class);

    $service->link($this->org, MEMBER_CACHE_CHAT, $this->owner);
    $service->unlink($this->org, TelegramGroup::query()->where('chat_id', MEMBER_CACHE_CHAT)->firstOrFail(), $this->owner);

    // The owner is no administrator any more: the next try asks Telegram again and is refused.
    $this->tg->chatMemberStatus = 'member';
    $owner = array_filter($this->tg->chatMemberLookups, fn (array $l): bool => $l['user_id'] === 4242);
    expect($owner)->toHaveCount(1);
    expect(fn () => $service->link($this->org, MEMBER_CACHE_CHAT, $this->owner))->toThrow(OrgGroupLinkException::class);

    expect(array_filter($this->tg->chatMemberLookups, fn (array $l): bool => $l['user_id'] === 4242))->toHaveCount(2)
        ->and(PureCache::store()->has(ChatMemberStatusCache::key(MEMBER_CACHE_CHAT, 4242)))->toBeFalse();
});

it('reads the bot id out of the token', function (): void {
    expect(app(ChatMemberStatusCache::class)->botId())->toBe(MEMBER_CACHE_BOT);

    config(['telegram.bot_token' => 'not-a-token']);
    expect(app(ChatMemberStatusCache::class)->botId())->toBeNull();
});

// --- the pure cache is down: the updates are business logic and go on ----------------------------------------------

it('takes the group out of service on my_chat_member left / kicked while the pure cache is down, and reports the failure', function (string $status): void {
    Exceptions::fake();
    $group = TelegramGroup::factory()->create(['chat_id' => MEMBER_CACHE_CHAT, 'status' => 'active']);
    memberCacheDownStore();

    memberCacheBotUpdate('administrator', $status);

    expect($group->fresh()?->status)->toBe('disabled');
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache is down');
})->with(['left', 'kicked']);

it('registers a group the bot was added to while the pure cache is down, and still asks Telegram about the bot', function (): void {
    Exceptions::fake();
    $this->tg->chatMemberStatus = 'member';
    memberCacheDownStore();

    memberCacheBotUpdate('left', 'member');

    expect(TelegramGroup::query()->where('chat_id', MEMBER_CACHE_CHAT)->value('status'))->toBe('pending')
        ->and(memberCacheBotLookups($this->tg))->toBe(1)
        ->and($this->tg->sent)->toHaveCount(1)
        ->and($this->tg->sent[0]['text'])->toBe(__('bot.group.bot_not_admin', [], 'ru'));
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache is down');
});

it('takes a learner out of the group on chat_member left while the pure cache is down: it does not touch that cache at all', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => MEMBER_CACHE_CHAT, 'status' => 'active']);
    $learner = Learner::factory()->memberOf($group)->create(['telegram_user_id' => 9001]);
    memberCacheDownStore();

    app(TelegramDispatcher::class)->dispatch(['chat_member' => [
        'chat' => ['id' => MEMBER_CACHE_CHAT, 'type' => 'supergroup', 'title' => 'Class 1'],
        'date' => now()->timestamp,
        'old_chat_member' => ['status' => 'member', 'user' => ['id' => 9001, 'is_bot' => false, 'first_name' => 'Ann']],
        'new_chat_member' => ['status' => 'left', 'user' => ['id' => 9001, 'is_bot' => false, 'first_name' => 'Ann']],
    ]]);

    expect(GroupMember::query()->where('learner_id', $learner->id)->where('telegram_group_id', $group->id)->value('status'))->toBe('left');
});

it('asks Telegram when the pure cache cannot be read, and does not even try to write', function (): void {
    Exceptions::fake();
    $group = TelegramGroup::factory()->create(['chat_id' => MEMBER_CACHE_CHAT, 'status' => 'active']);
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->once()->andThrow(new RuntimeException('redis-cache is down'));
    $store->shouldNotReceive('put');
    app()->instance(PureCache::CONTAINER_KEY, $store);
    $asked = 0;

    $status = app(ChatMemberStatusCache::class)->status($group->chat_id, MEMBER_CACHE_BOT, function () use (&$asked): string {
        $asked++;

        return 'administrator';
    });

    expect($status)->toBe('administrator')
        ->and($asked)->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache is down');
});

it('asks Telegram once and gives its answer when the pure cache can be read but not written, and reports the failed write', function (): void {
    Exceptions::fake();
    $group = TelegramGroup::factory()->create(['chat_id' => MEMBER_CACHE_CHAT, 'status' => 'active']);
    $key = ChatMemberStatusCache::key($group->chat_id, MEMBER_CACHE_BOT);
    // A miss on the read, a refusal on the write: the store is up when asked, and gone (or full, or read-only) when the answer is to be kept.
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('get')->once()->with($key)->andReturn(null);
    $store->shouldReceive('put')->once()->with($key, 'administrator', ChatMemberStatusCache::TTL)->andThrow(new RuntimeException('redis-cache refuses writes'));
    app()->instance(PureCache::CONTAINER_KEY, $store);
    $asked = 0;

    $status = app(ChatMemberStatusCache::class)->status($group->chat_id, MEMBER_CACHE_BOT, function () use (&$asked): string {
        $asked++;

        return 'administrator';
    });

    expect($status)->toBe('administrator')
        ->and($asked)->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'redis-cache refuses writes');
});
