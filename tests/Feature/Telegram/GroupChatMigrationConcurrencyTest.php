<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\TelegramGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeTelegramClient;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §4.6 (K1 of release B): the real races of a group's move to its supergroup. Several OS processes, each with its own
 * PostgreSQL connection, bring the news at the same instant: the two service messages, sends refused with `migrate_to_chat_id`,
 * and the bot's `my_chat_member` of the new supergroup that inserts a row for the new chat id. The unique `telegram_groups.chat_id`
 * and the `FOR UPDATE` of both rows are what the assertions rely on, so this file runs only on pgsql with pcntl. Data is committed.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

const RACE_OLD_CHAT = -4_300_000_001;
const RACE_NEW_CHAT = -1_004_300_000_001;

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    $this->app->instance(TelegramClient::class, new FakeTelegramClient);
});

afterEach(function (): void {
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** @return array<string, mixed> */
function raceMigrationMessage(bool $to, int $updateId): array
{
    return ['update_id' => $updateId, 'message' => $to
        ? ['message_id' => 1, 'date' => 1_764_000_000, 'from' => ['id' => 9100 + $updateId], 'chat' => ['id' => RACE_OLD_CHAT, 'type' => 'group'], 'migrate_to_chat_id' => RACE_NEW_CHAT]
        : ['message_id' => 1, 'date' => 1_764_000_000, 'from' => ['id' => 9100 + $updateId], 'chat' => ['id' => RACE_NEW_CHAT, 'type' => 'supergroup'], 'migrate_from_chat_id' => RACE_OLD_CHAT],
    ];
}

/**
 * The bot's `my_chat_member` in the new supergroup, handled the way HandleTelegramUpdate does: a unique violation (the row was moved
 * under us) fails the attempt and the queue runs the update again, up to three times.
 */
function raceBotJoinedSupergroup(): string
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            app(TelegramDispatcher::class)->dispatch(['update_id' => 50, 'my_chat_member' => [
                'chat' => ['id' => RACE_NEW_CHAT, 'type' => 'supergroup', 'title' => 'Class 8A'],
                'from' => ['id' => 9200],
                'date' => 1_764_000_000,
                'old_chat_member' => ['status' => 'left', 'user' => ['id' => 777, 'is_bot' => true]],
                'new_chat_member' => ['status' => 'member', 'user' => ['id' => 777, 'is_bot' => true]],
            ]]);

            return "joined on attempt {$attempt}";
        } catch (UniqueConstraintViolationException) {
            continue;
        }
    }

    return 'gave up';
}

/** @param list<array<string, mixed>> $results */
function assertMovedOnce(array $results, TelegramGroup $group): void
{
    expect(GameRace::crashes($results))->toBe([])
        ->and(TelegramGroup::query()->where('chat_id', RACE_OLD_CHAT)->count())->toBe(0)
        ->and(TelegramGroup::query()->where('chat_id', RACE_NEW_CHAT)->pluck('id')->all())->toBe([$group->id])
        ->and($group->fresh()?->status)->toBe('active');
}

it('moves the row exactly once when the service messages and refused sends arrive together', function (int $round): void {
    $group = TelegramGroup::factory()->create(['chat_id' => RACE_OLD_CHAT, 'status' => 'active']);

    $results = GameRace::run([
        fn () => app(GroupChatMigrator::class)->migrate(RACE_OLD_CHAT, RACE_NEW_CHAT)?->id,
        fn () => app(GroupChatMigrator::class)->migrate(RACE_OLD_CHAT, RACE_NEW_CHAT)?->id,
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(true, 1)),
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(false, 2)),
    ]);

    assertMovedOnce($results, $group);
    // Every caller that returned a row got the moved one.
    expect(array_values(array_unique(array_filter([$results[0]['value'] ?? null, $results[1]['value'] ?? null]))))->toBe([$group->id]);
})->with([1, 2, 3]);

it('ends with one row when the bot\'s my_chat_member of the supergroup inserts the new chat id in the middle of the move', function (int $round): void {
    $group = TelegramGroup::factory()->create(['chat_id' => RACE_OLD_CHAT, 'status' => 'active']);

    $results = GameRace::run([
        fn () => raceBotJoinedSupergroup(),
        fn () => app(GroupChatMigrator::class)->migrate(RACE_OLD_CHAT, RACE_NEW_CHAT)?->id,
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(true, 3)),
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(false, 4)),
    ]);

    assertMovedOnce($results, $group);
    expect($results[0]['value'] ?? null)->not->toBe('gave up');
})->with([1, 2, 3, 4, 5]);

it('merges a row the bot already created for the new chat, whoever of the racers gets there first', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => RACE_OLD_CHAT, 'status' => 'active']);
    expect(raceBotJoinedSupergroup())->toBe('joined on attempt 1');
    expect(TelegramGroup::query()->count())->toBe(2);

    $results = GameRace::run([
        fn () => app(GroupChatMigrator::class)->migrate(RACE_OLD_CHAT, RACE_NEW_CHAT)?->id,
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(true, 5)),
        fn () => app(TelegramDispatcher::class)->dispatch(raceMigrationMessage(false, 6)),
    ]);

    assertMovedOnce($results, $group);
    expect(DB::table('product_events')->where('telegram_group_id', $group->id)->where('name', 'group.bot_added')->count())->toBe(1);
});

it('runs the move again and merges when the new chat id is inserted between its lock and its update', function (): void {
    $group = TelegramGroup::factory()->create(['chat_id' => RACE_OLD_CHAT, 'status' => 'active']);

    // Deterministic interleaving: the inserter holds an uncommitted row of the new chat while the move locks the old row and tries
    // its UPDATE, which waits on the unique index and then fails once the inserter commits.
    $results = GameRace::run([
        function (): string {
            DB::transaction(function (): void {
                TelegramGroup::query()->create(['chat_id' => RACE_NEW_CHAT, 'title' => 'Class 8A', 'status' => 'pending', 'meta' => ['type' => 'supergroup']]);
                usleep(1_500_000);
            });

            return 'inserted';
        },
        function (): ?int {
            usleep(500_000);

            return app(GroupChatMigrator::class)->migrate(RACE_OLD_CHAT, RACE_NEW_CHAT)?->id;
        },
    ]);

    assertMovedOnce($results, $group);
    expect($results[0]['value'] ?? null)->toBe('inserted')
        ->and($results[1]['value'] ?? null)->toBe($group->id);
});
