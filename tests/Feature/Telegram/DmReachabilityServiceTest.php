<?php

declare(strict_types=1);

use App\Domain\Administration\Services\BotBlockedCounter;
use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\DmReachability;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * docs/34 §4в п. 3, 6 (B2, B7): DmReachability is the one writer of `dm_blocked_at` / `dm_blocked_reason`. The first moment stays,
 * the reason follows the latest answer, `bot.dm_closed` / `bot.dm_reopened` are recorded on the transition only, a dead account keeps
 * no reminder pointer, and the dashboard counter is dropped on a transition.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00:00', 'UTC'));
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->dm = app(DmReachability::class);
});

/** @return list<array<string, mixed>> */
function dmEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => $e->props)->all();
}

it('closes the DM once: the date stays, the reason follows the latest answer, one bot.dm_closed', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 5_001]);

    $this->dm->markUnreachable(5_001, 'bot_blocked');
    $this->travel(2)->hours();
    $this->dm->markUnreachable(5_001, 'kicked', via: DmReachability::VIA_MY_CHAT_MEMBER);

    $fresh = $learner->fresh();
    expect($fresh?->dm_blocked_at?->utc()->toDateTimeString())->toBe('2026-11-25 09:00:00')
        ->and($fresh?->dm_blocked_reason)->toBe('kicked')
        ->and(dmEvents('bot.dm_closed'))->toEqual([['reason' => 'bot_blocked', 'via' => 'send']]);
    expect(ProductEvent::query()->where('name', 'bot.dm_closed')->value('learner_id'))->toBe($learner->id);
});

it('reopens the DM once: both columns are cleared, one bot.dm_reopened with its via', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 5_002, 'dm_blocked_at' => now()->subDay(), 'dm_blocked_reason' => 'bot_blocked']);

    $this->dm->markReachable(5_002, DmReachability::VIA_DM);
    $this->dm->markReachable(5_002, DmReachability::VIA_DM);

    expect($learner->fresh()?->dm_blocked_at)->toBeNull()
        ->and($learner->fresh()?->dm_blocked_reason)->toBeNull()
        ->and(dmEvents('bot.dm_reopened'))->toBe([['via' => 'dm']]);
});

it('records a closed -> open -> closed round trip as two events of each kind at most', function (): void {
    Learner::factory()->create(['telegram_user_id' => 5_003]);

    $this->dm->markUnreachable(5_003, 'bot_blocked');
    $this->dm->markReachable(5_003, DmReachability::VIA_START);
    $this->dm->markUnreachable(5_003, 'not_started');
    $this->dm->markUnreachable(5_003, 'not_started');

    expect(dmEvents('bot.dm_closed'))->toHaveCount(2)
        ->and(dmEvents('bot.dm_reopened'))->toBe([['via' => 'start']]);
});

it('stores an unknown reason as forbidden and ignores a Telegram id of nobody', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 5_004]);

    $this->dm->markUnreachable(5_004, 'something new');
    $this->dm->markUnreachable(5_999, 'bot_blocked');
    $this->dm->markReachable(5_998, DmReachability::VIA_DM);
    $this->dm->markUnreachable(0, 'bot_blocked');

    expect($learner->fresh()?->dm_blocked_reason)->toBe('forbidden')
        ->and(ProductEvent::query()->count())->toBe(1)
        ->and(Learner::query()->count())->toBe(1);
});

it('takes the date of my_chat_member and closes with the reason kicked; member reopens; /start reopens too', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 5_005]);
    $dispatch = fn (string $status, int $date) => app(TelegramDispatcher::class)->dispatch(['my_chat_member' => [
        'chat' => ['id' => 5_005, 'type' => 'private'],
        'from' => ['id' => 5_005, 'first_name' => 'Ali'],
        'date' => $date,
        'old_chat_member' => ['status' => $status === 'kicked' ? 'member' : 'kicked'],
        'new_chat_member' => ['status' => $status],
    ]]);

    $dispatch('kicked', CarbonImmutable::parse('2026-11-24 22:10:05', 'UTC')->getTimestamp());
    expect($learner->fresh()?->dm_blocked_at?->utc()->toDateTimeString())->toBe('2026-11-24 22:10:05')
        ->and($learner->fresh()?->dm_blocked_reason)->toBe('kicked')
        ->and(dmEvents('bot.dm_closed'))->toEqual([['reason' => 'kicked', 'via' => 'my_chat_member']]);

    $dispatch('member', now()->getTimestamp());
    expect($learner->fresh()?->dm_blocked_at)->toBeNull()
        ->and(dmEvents('bot.dm_reopened'))->toBe([['via' => 'my_chat_member']]);

    $this->dm->markUnreachable(5_005, 'bot_blocked');
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => 5_005, 'type' => 'private'],
        'from' => ['id' => 5_005, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => '/start',
    ]]);
    expect($learner->fresh()?->dm_blocked_at)->toBeNull()
        ->and(dmEvents('bot.dm_reopened'))->toHaveCount(2);
});

it('keeps no reminder pointer for a dead account, and the backfill gives it back once the DM is open again (B7)', function (): void {
    $dead = Learner::factory()->create(['telegram_user_id' => 5_006, 'reminder_time' => '19:00', 'next_reminder_at' => now()->subHour()]);
    $deleted = Learner::factory()->create(['telegram_user_id' => 5_007, 'reminder_time' => '19:00', 'next_reminder_at' => now()->addHour()]);
    $blocked = Learner::factory()->create(['telegram_user_id' => 5_008, 'reminder_time' => '19:00', 'next_reminder_at' => now()->addHour()]);

    $this->dm->markUnreachable(5_006, 'user_deactivated');
    $this->dm->markUnreachable(5_007, 'chat_not_found');
    $this->dm->markUnreachable(5_008, 'bot_blocked');

    expect($dead->fresh()?->next_reminder_at)->toBeNull()
        ->and($deleted->fresh()?->next_reminder_at)->toBeNull()
        // A person who blocked the bot may unblock it: their pointer stays as it was.
        ->and($blocked->fresh()?->next_reminder_at)->not->toBeNull();

    $report = app(ReminderDispatcher::class)->dispatchDue();
    expect($report->backfilled)->toBe(0)
        ->and($dead->fresh()?->next_reminder_at)->toBeNull();

    // A second answer of the same kind keeps it cleared; a reopened DM is planned again by the backfill.
    $this->dm->markReachable(5_006, DmReachability::VIA_DM);
    expect(app(ReminderDispatcher::class)->backfillMissing())->toBe(1)
        ->and($dead->fresh()?->next_reminder_at)->not->toBeNull();
});

it('clears the pointer when a closed DM turns out to be a dead account later', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 5_009, 'reminder_time' => '19:00', 'next_reminder_at' => now()->addHour()]);

    $this->dm->markUnreachable(5_009, 'bot_blocked');
    $this->dm->markUnreachable(5_009, 'user_deactivated');

    expect($learner->fresh()?->next_reminder_at)->toBeNull()
        ->and($learner->fresh()?->dm_blocked_reason)->toBe('user_deactivated');
});

it('drops the counter of the dashboard on a transition, not on a repeated answer', function (): void {
    Learner::factory()->create(['telegram_user_id' => 5_010]);
    expect(BotBlockedCounter::counts())->toBe(['total' => 0, 'week' => 0]);

    $this->dm->markUnreachable(5_010, 'bot_blocked');
    expect(BotBlockedCounter::counts())->toBe(['total' => 1, 'week' => 1]);

    PureCache::store()->put(BotBlockedCounter::KEY, ['total' => 7, 'week' => 7], 60);
    $this->dm->markUnreachable(5_010, 'kicked');
    expect(BotBlockedCounter::counts())->toBe(['total' => 7, 'week' => 7]);

    $this->dm->markReachable(5_010, DmReachability::VIA_DM);
    expect(BotBlockedCounter::counts())->toBe(['total' => 0, 'week' => 0]);
});
