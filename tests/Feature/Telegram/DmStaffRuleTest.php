<?php

declare(strict_types=1);

use App\Domain\Learning\Services\TeacherAuthority;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Domain\Telegram\Services\TelegramDispatcher;
use App\Domain\Telegram\Support\DmLearnerResolver;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * Who is "staff" in a private chat (review W1 of the audit 2026-11-10): a VERIFIED `users.telegram_user_id`, or an unverified one
 * with no learner behind it (the legacy teachers). A hand-typed id that a learner also carries is the learner's — a number typed in
 * /admin must not switch somebody's bot off. Group commands keep their own rule (`TeacherAuthority`).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);
    $this->resolver = app(DmLearnerResolver::class);
});

function dsrDm(int $tgId, string $text): string
{
    app(TelegramDispatcher::class)->dispatch(['message' => [
        'message_id' => 1,
        'chat' => ['id' => $tgId, 'type' => 'private'],
        'from' => ['id' => $tgId, 'first_name' => 'Sardor', 'language_code' => 'ru'],
        'text' => $text,
    ]]);

    return (string) end(test()->tg->sent)['text'];
}

it('decides staff by the one rule: verified → staff; unverified → staff only without a learner row; nobody → not staff', function (string $case, bool $staff): void {
    match ($case) {
        'verified, no learner' => User::factory()->create(['telegram_user_id' => 5001, 'telegram_verified_at' => now()]),
        'verified, learner too' => [User::factory()->create(['telegram_user_id' => 5001, 'telegram_verified_at' => now()]), Learner::factory()->onboarded()->create(['telegram_user_id' => 5001])],
        'typed, no learner' => User::factory()->create(['telegram_user_id' => 5001, 'telegram_verified_at' => null]),
        'typed, learner exists' => [User::factory()->create(['telegram_user_id' => 5001, 'telegram_verified_at' => null]), Learner::factory()->onboarded()->create(['telegram_user_id' => 5001])],
        'typed, learner soft-deleted' => [User::factory()->create(['telegram_user_id' => 5001, 'telegram_verified_at' => null]), tap(Learner::factory()->onboarded()->create(['telegram_user_id' => 5001]))->delete()],
        'no user' => Learner::factory()->onboarded()->create(['telegram_user_id' => 5001]),
        'nobody' => null,
    };

    expect($this->resolver->isStaff(5001))->toBe($staff)
        ->and($this->resolver->isStaff(0))->toBeFalse()
        ->and($this->resolver->resolve(['id' => 5001, 'first_name' => 'S']) === null)->toBe($staff);
})->with([
    'verified, no learner' => ['verified, no learner', true],
    'verified, learner too' => ['verified, learner too', true],
    'typed, no learner' => ['typed, no learner', true],
    'typed, learner exists' => ['typed, learner exists', false],
    'typed, learner soft-deleted' => ['typed, learner soft-deleted', false],
    'no user' => ['no user', false],
    'nobody' => ['nobody', false],
]);

it('keeps the bot working for a learner whose Telegram id somebody typed into /admin: /start, /today and /help answer as to a learner', function (): void {
    $learner = Learner::factory()->onboarded()->create(['telegram_user_id' => 5002, 'interface_locale' => 'ru']);
    User::factory()->create(['telegram_user_id' => 5002, 'telegram_verified_at' => null, 'name' => 'Typed Teacher']);

    expect(dsrDm(5002, '/start'))->not->toContain('аккаунт учителя')
        ->and(dsrDm(5002, '/today'))->not->toBe('Эта команда — для учеников. Ваши команды учителя: /help')
        ->and(dsrDm(5002, '/help'))->not->toContain('команды учителя')
        ->and($learner->fresh())->not->toBeNull();
});

it('greets a legacy teacher (hand-typed id, no learner row) as a teacher in /start and /help, as before', function (): void {
    User::factory()->create(['telegram_user_id' => 5003, 'telegram_verified_at' => null, 'name' => 'Old Teacher']);

    expect(dsrDm(5003, '/start'))->toContain('Ваш аккаунт учителя подключён')
        ->and(dsrDm(5003, '/help'))->toContain('LexiFlow — команды учителя')
        ->and(dsrDm(5003, '/help'))->toContain('/unlink')
        ->and(dsrDm(5003, '/today'))->toBe('Эта команда — для учеников. Ваши команды учителя: /help')
        ->and(Learner::query()->where('telegram_user_id', 5003)->exists())->toBeFalse();
});

it('greets a verified staff member as a teacher even when a learner row with the same id exists', function (): void {
    Learner::factory()->onboarded()->create(['telegram_user_id' => 5004]);
    User::factory()->create(['telegram_user_id' => 5004, 'telegram_verified_at' => now(), 'name' => 'Verified']);

    expect(dsrDm(5004, '/start'))->toContain('Ваш аккаунт учителя подключён')
        ->and(dsrDm(5004, '/today'))->toBe('Эта команда — для учеников. Ваши команды учителя: /help');
});

it('leaves the teacher commands to their own rules: an unverified teacher still teaches their group and gets /stats', function (): void {
    $teacher = User::factory()->create(['telegram_user_id' => 5005, 'telegram_verified_at' => null, 'is_active' => true]);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);

    expect(app(TeacherAuthority::class)->canTeach($teacher, $group))->toBeTrue()
        ->and(dsrDm(5005, '/stats'))->not->toContain('Отчёт доступен учителям');
});
