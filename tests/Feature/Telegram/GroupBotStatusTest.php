<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\GroupChatMigrator;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * docs/33 §2.1 (K2): `telegram_groups.bot_status` / `bot_status_at` mirror the bot's status from the latest `my_chat_member` by its
 * `date`. An older update changes nothing - neither the mirror nor `status` - so an update delivered late cannot disable a group the
 * bot is back in, nor greet a group it has left.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'UTC'));
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function botStatusUpdate(int $chatId, string $old, string $new, string $at): void
{
    app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'English Friends'],
        'from' => ['id' => 42, 'first_name' => 'Admin'],
        'date' => CarbonImmutable::parse($at, 'UTC')->getTimestamp(),
        'old_chat_member' => ['status' => $old, 'user' => ['id' => 999, 'is_bot' => true]],
        'new_chat_member' => ['status' => $new, 'user' => ['id' => 999, 'is_bot' => true]],
    ]]);
}

function botGroup(int $chatId): ?TelegramGroup
{
    return TelegramGroup::query()->where('chat_id', $chatId)->first();
}

it('records the bot added as a member with the date of the update', function (): void {
    botStatusUpdate(-100_1, 'left', 'member', '2026-11-25 11:59:30');

    $group = botGroup(-100_1);
    expect($group?->status)->toBe('pending')
        ->and($group?->bot_status)->toBe('member')
        ->and($group?->bot_status_at?->utc()->toDateTimeString())->toBe('2026-11-25 11:59:30');
});

it('follows a promotion to administrator without greeting again', function (): void {
    botStatusUpdate(-100_2, 'left', 'member', '2026-11-25 11:00:00');
    $greetings = count($this->tg->sent);

    botStatusUpdate(-100_2, 'member', 'administrator', '2026-11-25 11:05:00');

    expect(botGroup(-100_2)?->bot_status)->toBe('administrator')
        ->and(botGroup(-100_2)?->bot_status_at?->utc()->toDateTimeString())->toBe('2026-11-25 11:05:00')
        ->and($this->tg->sent)->toHaveCount($greetings);
});

it('records the removal (left / kicked) together with the disabled group', function (string $status): void {
    botStatusUpdate(-100_3, 'left', 'member', '2026-11-25 11:00:00');
    TelegramGroup::query()->where('chat_id', -100_3)->first()?->update(['status' => 'active']);

    botStatusUpdate(-100_3, 'member', $status, '2026-11-25 11:10:00');

    expect(botGroup(-100_3)?->status)->toBe('disabled')
        ->and(botGroup(-100_3)?->bot_status)->toBe($status)
        ->and(botGroup(-100_3)?->bot_status_at?->utc()->toDateTimeString())->toBe('2026-11-25 11:10:00');
})->with(['left', 'kicked']);

it('ignores an older removal that arrives after a newer re-add: the group stays and the mirror says member', function (): void {
    botStatusUpdate(-100_4, 'left', 'member', '2026-11-25 11:00:00');
    TelegramGroup::query()->where('chat_id', -100_4)->first()?->update(['status' => 'active']);

    botStatusUpdate(-100_4, 'kicked', 'member', '2026-11-25 11:20:00');
    botStatusUpdate(-100_4, 'member', 'kicked', '2026-11-25 11:10:00'); // delivered late

    expect(botGroup(-100_4)?->status)->toBe('active')
        ->and(botGroup(-100_4)?->bot_status)->toBe('member')
        ->and(botGroup(-100_4)?->bot_status_at?->utc()->toDateTimeString())->toBe('2026-11-25 11:20:00');
});

it('ignores an older addition that arrives after a newer removal: no greeting, the mirror says left', function (): void {
    botStatusUpdate(-100_5, 'left', 'member', '2026-11-25 11:00:00');
    $sent = count($this->tg->sent);

    botStatusUpdate(-100_5, 'member', 'left', '2026-11-25 11:30:00');
    botStatusUpdate(-100_5, 'left', 'member', '2026-11-25 11:15:00'); // delivered late

    expect(botGroup(-100_5)?->bot_status)->toBe('left')
        ->and(botGroup(-100_5)?->status)->toBe('disabled')
        ->and($this->tg->sent)->toHaveCount($sent);
});

it('applies two updates of the same second in their order of arrival', function (): void {
    botStatusUpdate(-100_6, 'left', 'member', '2026-11-25 11:00:00');
    botStatusUpdate(-100_6, 'member', 'administrator', '2026-11-25 11:00:00');

    expect(botGroup(-100_6)?->bot_status)->toBe('administrator');
});

it('carries the status of the new supergroup into the surviving row when the fresh row is merged', function (): void {
    botStatusUpdate(-100_7, 'left', 'member', '2026-11-25 10:00:00');
    botStatusUpdate(-100_7, 'member', 'left', '2026-11-25 10:30:00');   // the old group: the bot "left" with the upgrade
    botStatusUpdate(-100_8, 'left', 'administrator', '2026-11-25 10:31:00'); // the supergroup's fresh row

    $survivor = app(GroupChatMigrator::class)->migrate(-100_7, -100_8);

    expect($survivor?->chat_id)->toBe(-100_8)
        ->and(TelegramGroup::query()->count())->toBe(1)
        ->and($survivor?->fresh()?->bot_status)->toBe('administrator')
        ->and($survivor?->fresh()?->bot_status_at?->utc()->toDateTimeString())->toBe('2026-11-25 10:31:00');
});
