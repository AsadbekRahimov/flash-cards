<?php

declare(strict_types=1);

use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Twa\Services\InitDataValidator;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * `learners.dm_blocked_at` (docs/12 §7.5): set by the reminder sender on a Telegram 403,
 * cleared by ANY message or button tap of the learner in the private chat — whichever
 * handler ends up answering — and by nothing else.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.base_url' => 'https://twa.test']);
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
});

function dmBlockedLearner(int $tgId): Learner
{
    return Learner::factory()->create([
        'telegram_user_id' => $tgId,
        'dm_blocked_at' => now()->subDay(),
        'next_reminder_at' => now()->subHour(),
    ]);
}

/** @param array<string, mixed> $chat */
function dmSend(int $tgId, string $text, array $chat = []): void
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $tgId, 'type' => 'private', ...$chat],
        'from' => ['id' => $tgId, 'first_name' => 'Ali', 'language_code' => 'ru'],
        'text' => $text,
    ]]);
}

it('clears dm_blocked_at on a DM command that does not resolve a learner itself', function (string $text): void {
    $learner = dmBlockedLearner(8101);

    dmSend(8101, $text);

    expect($learner->fresh()->dm_blocked_at)->toBeNull()
        // the pointer is untouched: the next dispatcher run picks the learner up again
        ->and($learner->fresh()->next_reminder_at)->not->toBeNull();
})->with(['/help', '/stats', '/promo', '/promo ABC123', '/settings', '/today', '/start', 'hello there']);

it('clears dm_blocked_at on a button tap in the private chat', function (): void {
    $learner = dmBlockedLearner(8102);

    app(TelegramDispatcher::class)->dispatch(['callback_query' => [
        'id' => 'cb-1',
        'from' => ['id' => 8102, 'first_name' => 'Ali'],
        'message' => ['message_id' => 1, 'chat' => ['id' => 8102, 'type' => 'private']],
        'data' => 'onb1:locale:ru',
    ]]);

    expect($learner->fresh()->dm_blocked_at)->toBeNull();
});

it('does not touch dm_blocked_at for a message in a group, nor for another person\'s DM', function (): void {
    $learner = dmBlockedLearner(8103);
    $group = TelegramGroup::factory()->create(['status' => 'active', 'chat_id' => -100800]);

    app(TelegramDispatcher::class)->dispatch(['message' => [
        'chat' => ['id' => $group->chat_id, 'type' => 'supergroup'],
        'from' => ['id' => 8103, 'first_name' => 'Ali'],
        'text' => '/help',
    ]]);
    dmSend(8104, '/help');

    expect($learner->fresh()->dm_blocked_at)->not->toBeNull()
        ->and(Learner::query()->where('telegram_user_id', 8104)->exists())->toBeFalse();
});

it('does not create a learner row just to clear the flag, and ignores a teacher\'s DM quietly', function (): void {
    User::factory()->create(['telegram_user_id' => 8105, 'is_active' => true]);

    dmSend(8105, '/help');
    dmSend(8106, '/stats');

    expect(Learner::query()->whereIn('telegram_user_id', [8105, 8106])->count())->toBe(0);
});

it('does not clear dm_blocked_at on a TWA login: opening the app does not prove the DM is open', function (): void {
    $learner = dmBlockedLearner(8107);

    $this->postJson('/api/twa/auth', [
        'init_data' => InitDataValidator::sign('test-bot-token-1234567890', [
            'query_id' => 'AAHdF6IQAAAAAN0XohDhrOrc',
            'user' => json_encode(['id' => 8107, 'first_name' => 'Ali', 'language_code' => 'ru']),
            'auth_date' => (string) time(),
        ]),
    ])->assertOk();

    expect($learner->fresh()->dm_blocked_at)->not->toBeNull();
});

it('does not clear the flag for a flooding sender (the inbound limit drops the update first)', function (): void {
    config(['telegram.inbound_rate_limit.max_messages' => 2, 'telegram.inbound_rate_limit.window_seconds' => 60]);
    $learner = Learner::factory()->create(['telegram_user_id' => 8108]);

    dmSend(8108, 'one');
    dmSend(8108, 'two');
    $learner->forceFill(['dm_blocked_at' => now()])->save();
    dmSend(8108, 'three');

    expect($learner->fresh()->dm_blocked_at)->not->toBeNull();
});
