<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Exceptions\TelegramChatMigratedException;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Domain\Telegram\Services\IrazasyedTelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Services\TelegramKeyboardFactory;
use App\Domain\Telegram\Services\TelegramMessageRateLimiter;
use App\Jobs\SendTelegramMessageJob;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\TelegramGroup;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Objects\Message as MessageObject;
use Telegram\Bot\TelegramRequest;
use Telegram\Bot\TelegramResponse;
use Tests\Support\FakeTelegramClient;

/*
 * docs/33 §4.6 (K1 of release B): a group that became a supergroup keeps its row (organization, teachers, members, sessions): the
 * row follows the new chat id, whether the service messages or a refused send bring the news first, in any order, any number of times.
 */

uses(RefreshDatabase::class);

const OLD_CHAT = -4_200_000_001;
const NEW_CHAT = -1_004_200_000_001;

beforeEach(function (): void {
    $this->app->instance(TelegramClient::class, new FakeTelegramClient);
});

/** @param array<string, mixed> $message */
function migrationUpdate(array $message, int $updateId = 1): array
{
    return ['update_id' => $updateId, 'message' => array_replace([
        'message_id' => 1,
        'date' => 1_764_000_000,
        'from' => ['id' => 9001, 'is_bot' => false, 'first_name' => 'Admin'],
    ], $message)];
}

function migrateToUpdate(): array
{
    return migrationUpdate(['chat' => ['id' => OLD_CHAT, 'type' => 'group', 'title' => 'Class 7B'], 'migrate_to_chat_id' => NEW_CHAT], 1);
}

function migrateFromUpdate(): array
{
    return migrationUpdate(['chat' => ['id' => NEW_CHAT, 'type' => 'supergroup', 'title' => 'Class 7B'], 'migrate_from_chat_id' => OLD_CHAT], 2);
}

function memberOf(TelegramGroup $group, Learner $learner, string $status = 'active'): void
{
    DB::table('group_members')->insert([
        'telegram_group_id' => $group->id,
        'learner_id' => $learner->id,
        'status' => $status,
        'joined_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// --- the move ----------------------------------------------------------------------------------

it('moves the group row to the new chat id and keeps everything that hangs on it', function (): void {
    $organization = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT, 'organization_id' => $organization->id, 'meta' => ['locale' => 'uz']]);
    $teacher = User::factory()->create();
    $group->teachers()->attach($teacher->id);
    memberOf($group, Learner::factory()->create());

    $moved = app(GroupChatMigrator::class)->migrate(OLD_CHAT, NEW_CHAT);

    $group->refresh();
    expect($moved?->id)->toBe($group->id)
        ->and($group->chat_id)->toBe(NEW_CHAT)
        ->and($group->status)->toBe('active')
        ->and($group->organization_id)->toBe($organization->id)
        ->and($group->meta)->toMatchArray(['locale' => 'uz', 'type' => 'supergroup', 'migrated_from_chat_id' => OLD_CHAT])
        ->and($group->teachers()->count())->toBe(1)
        ->and($group->members()->count())->toBe(1)
        ->and(TelegramGroup::query()->count())->toBe(1);
});

it('is a no-op the second time and for a chat we never knew', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $migrator = app(GroupChatMigrator::class);

    $migrator->migrate(OLD_CHAT, NEW_CHAT);
    $again = $migrator->migrate(OLD_CHAT, NEW_CHAT);

    expect($again?->id)->toBe($group->id)
        ->and($group->fresh()?->chat_id)->toBe(NEW_CHAT)
        ->and($migrator->migrate(-777, -100777))->toBeNull()
        ->and($migrator->migrate(OLD_CHAT, OLD_CHAT))->toBeNull()
        ->and($migrator->migrate(0, NEW_CHAT))->toBeNull()
        ->and(TelegramGroup::query()->count())->toBe(1);
});

it('follows the service messages in either order, both handled, the second one a no-op', function (array $order): void {
    $group = TelegramGroup::factory()->pending()->create(['chat_id' => OLD_CHAT]);
    $dispatcher = app(TelegramDispatcher::class);

    foreach ($order as $update) {
        $dispatcher->dispatch($update());
    }

    expect($group->fresh()?->chat_id)->toBe(NEW_CHAT)
        ->and(TelegramGroup::query()->count())->toBe(1);
})->with([
    'to, then from' => [[fn () => migrateToUpdate(), fn () => migrateFromUpdate()]],
    'from, then to' => [[fn () => migrateFromUpdate(), fn () => migrateToUpdate()]],
    'the same twice' => [[fn () => migrateToUpdate(), fn () => migrateToUpdate()]],
]);

it('handles the move even when the admin who upgraded the group is over the inbound flood limit', function (): void {
    config(['telegram.inbound_rate_limit.max_messages' => 1]);
    $group = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $dispatcher = app(TelegramDispatcher::class);

    $dispatcher->dispatch(migrationUpdate(['chat' => ['id' => OLD_CHAT, 'type' => 'group'], 'text' => 'hello'], 7));
    $dispatcher->dispatch(migrateToUpdate());

    expect($group->fresh()?->chat_id)->toBe(NEW_CHAT);
});

// --- a row of the new chat already exists (my_chat_member of the supergroup came first) -----------

it('merges the fresh row of the new chat into the old group: members and events move, the old row survives', function (): void {
    $old = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT, 'status' => 'active']);
    $fresh = TelegramGroup::factory()->pending()->create(['chat_id' => NEW_CHAT, 'meta' => ['type' => 'supergroup']]);
    $both = Learner::factory()->create();
    $onlyNew = Learner::factory()->create();
    memberOf($old, $both);
    memberOf($fresh, $both, 'left');
    memberOf($fresh, $onlyNew);
    DB::table('product_events')->insert(['name' => 'group.bot_added', 'telegram_group_id' => $fresh->id, 'props' => '{}', 'occurred_at' => now()]);

    $survivor = app(GroupChatMigrator::class)->migrate(OLD_CHAT, NEW_CHAT);

    expect($survivor?->id)->toBe($old->id)
        ->and(TelegramGroup::query()->pluck('id')->all())->toBe([$old->id])
        ->and($old->fresh()?->chat_id)->toBe(NEW_CHAT)
        ->and($old->fresh()?->status)->toBe('active')
        // The survivor's own membership wins for a learner who is in both.
        ->and(DB::table('group_members')->where('telegram_group_id', $old->id)->orderBy('learner_id')->pluck('status', 'learner_id')->all())
        ->toBe([$both->id => 'active', $onlyNew->id => 'active'])
        ->and(DB::table('product_events')->where('telegram_group_id', $old->id)->count())->toBe(1);
});

it('does not merge a row of the new chat that has a life of its own, and says so', function (string $life): void {
    Log::spy();
    $old = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $other = TelegramGroup::factory()->pending()->create(['chat_id' => NEW_CHAT]);
    match ($life) {
        'active' => $other->update(['status' => 'active']),
        'center' => $other->update(['organization_id' => Organization::factory()->create()->id]),
        'teacher' => $other->teachers()->attach(User::factory()->create()->id),
    };

    $result = app(GroupChatMigrator::class)->migrate(OLD_CHAT, NEW_CHAT);

    expect($result?->id)->toBe($other->id)
        ->and($old->fresh()?->chat_id)->toBe(OLD_CHAT)
        ->and($other->fresh()?->chat_id)->toBe(NEW_CHAT);
    Log::shouldHaveReceived('error')->with('telegram.group_migration_conflict', ['old_group_id' => $old->id, 'new_group_id' => $other->id])->once();
})->with(['active', 'center', 'teacher']);

// --- the send that meets the migration -----------------------------------------------------------

/**
 * An SDK whose sendMessage answers "migrated" for the chats in `$migrated`, and delivers to any other chat.
 *
 * @param  array<int, int>  $migrated  old chat id => new chat id
 */
function migratingSdk(array $migrated): Api
{
    return new class($migrated) extends Api
    {
        /** @var list<int|string> */
        public array $attempts = [];

        /** @var list<int|string> */
        public array $delivered = [];

        /** @param array<int, int> $migrated */
        public function __construct(private readonly array $migrated)
        {
            parent::__construct('123456789:AAHmigratemigratemigratemigrate00042');
        }

        private function refuseIfMigrated(int|string $chatId, string $endpoint): void
        {
            if (isset($this->migrated[(int) $chatId])) {
                throw new TelegramResponseException(new TelegramResponse(
                    new TelegramRequest('t', 'POST', $endpoint),
                    new Response(400, [], json_encode([
                        'ok' => false,
                        'error_code' => 400,
                        'description' => 'Bad Request: group chat was upgraded to a supergroup chat',
                        'parameters' => ['migrate_to_chat_id' => $this->migrated[(int) $chatId]],
                    ], JSON_THROW_ON_ERROR)),
                ));
            }
        }

        /** @param array<string, mixed> $params */
        public function sendMessage(array $params): MessageObject
        {
            $this->attempts[] = $params['chat_id'];
            $this->refuseIfMigrated($params['chat_id'], 'sendMessage');
            $this->delivered[] = $params['chat_id'];

            return new MessageObject([]);
        }

        /** @param array<string, mixed> $params */
        public function editMessageText(array $params): MessageObject
        {
            $this->attempts[] = $params['chat_id'];
            $this->refuseIfMigrated($params['chat_id'], 'editMessageText');

            return new MessageObject([]);
        }
    };
}

it('moves the group and delivers the refused message to the new chat exactly once', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $sdk = migratingSdk([OLD_CHAT => NEW_CHAT]);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendMessage(OLD_CHAT, 'Leaderboard of the week');

    expect($sdk->attempts)->toBe([OLD_CHAT, NEW_CHAT])
        ->and($sdk->delivered)->toBe([NEW_CHAT])
        ->and($group->fresh()?->chat_id)->toBe(NEW_CHAT);

    // The next send reads the moved row: straight to the new chat.
    $client->sendMessage((int) $group->fresh()?->chat_id, 'Next week');
    expect($sdk->delivered)->toBe([NEW_CHAT, NEW_CHAT]);
});

it('delivers a deferred message of the old chat to the new one as well', function (): void {
    TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $sdk = migratingSdk([OLD_CHAT => NEW_CHAT]);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    (new SendTelegramMessageJob(OLD_CHAT, 'Deferred'))->handle($client, new TelegramMessageRateLimiter);

    expect($sdk->delivered)->toBe([NEW_CHAT]);
});

it('still delivers to the new chat when no group row exists', function (): void {
    $sdk = migratingSdk([OLD_CHAT => NEW_CHAT]);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    $client->sendImmediately(OLD_CHAT, 'Unknown group');

    expect($sdk->delivered)->toBe([NEW_CHAT])->and(TelegramGroup::query()->count())->toBe(0);
});

it('repeats a send only once: a second migration answer is thrown to the caller', function (): void {
    $sdk = migratingSdk([OLD_CHAT => NEW_CHAT, NEW_CHAT => -1_009_999]);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    expect(fn () => $client->sendImmediately(OLD_CHAT, 'hi'))->toThrow(TelegramChatMigratedException::class);
    expect($sdk->attempts)->toBe([OLD_CHAT, NEW_CHAT])->and($sdk->delivered)->toBe([]);
});

it('moves the group on an edit but does not repeat it (message ids differ in the new chat)', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => OLD_CHAT]);
    $sdk = migratingSdk([OLD_CHAT => NEW_CHAT]);
    $client = new IrazasyedTelegramClient($sdk, new TelegramKeyboardFactory, new TelegramMessageRateLimiter);

    expect(fn () => $client->editMessageText(OLD_CHAT, 55, 'edited'))->toThrow(TelegramChatMigratedException::class);
    expect($sdk->attempts)->toBe([OLD_CHAT])
        ->and($group->fresh()?->chat_id)->toBe(NEW_CHAT);
});
