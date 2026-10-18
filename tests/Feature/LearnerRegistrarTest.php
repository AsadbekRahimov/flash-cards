<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\Learner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('creates a learner with the configured defaults', function (): void {
    config([
        'learners.default_timezone' => 'Asia/Samarkand',
        'learners.default_daily_goal' => 40,
        'learners.default_reminder_time' => '08:00',
    ]);

    $learner = app(LearnerRegistrar::class)->fromTelegramUser([
        'id' => 4001,
        'first_name' => 'Alex',
        'last_name' => 'Doe',
        'username' => 'alexd',
        'language_code' => 'uz',
    ])->fresh();

    expect($learner->telegram_user_id)->toBe(4001)
        ->and($learner->first_name)->toBe('Alex')
        ->and($learner->last_name)->toBe('Doe')
        ->and($learner->username)->toBe('alexd')
        ->and($learner->language_code)->toBe('uz')
        ->and($learner->interface_locale)->toBe('uz')
        ->and($learner->timezone)->toBe('Asia/Samarkand')
        ->and($learner->daily_goal)->toBe(40)
        ->and(substr((string) DB::table('learners')->where('id', $learner->id)->value('reminder_time'), 0, 5))->toBe('08:00')
        ->and($learner->onboarding_state)->toBe('new')
        ->and($learner->last_seen_at)->not->toBeNull()
        ->and($learner->acquisition_source)->toBeNull();
});

it('falls back to the default locale for an unsupported Telegram language', function (): void {
    $learner = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 4002, 'first_name' => 'A', 'language_code' => 'en']);

    expect($learner->interface_locale)->toBe('ru');
});

it('is an upsert by telegram_user_id and refreshes the profile and last_seen_at', function (): void {
    $registrar = app(LearnerRegistrar::class);
    $first = $registrar->fromTelegramUser(['id' => 4003, 'first_name' => 'Old', 'username' => 'old_u']);
    Learner::query()->whereKey($first->id)->update(['last_seen_at' => now()->subDays(3)]);

    $second = $registrar->fromTelegramUser(['id' => 4003, 'first_name' => 'New', 'last_name' => 'Name']);

    expect($second->id)->toBe($first->id)
        ->and(Learner::count())->toBe(1)
        ->and($second->first_name)->toBe('New')
        ->and($second->last_name)->toBe('Name')
        // A field Telegram no longer sends is cleared, not kept stale.
        ->and($second->username)->toBeNull()
        ->and($second->last_seen_at?->isToday())->toBeTrue();
});

it('keeps the chosen interface locale once onboarding is not new any more', function (): void {
    $registrar = app(LearnerRegistrar::class);
    $learner = $registrar->fromTelegramUser(['id' => 4004, 'first_name' => 'A', 'language_code' => 'ru']);
    $learner->update(['interface_locale' => 'uz', 'onboarding_state' => 'goal']);

    $again = $registrar->fromTelegramUser(['id' => 4004, 'first_name' => 'A', 'language_code' => 'ru']);

    expect($again->interface_locale)->toBe('uz');
});

it('refreshes the locale from the Telegram language only while onboarding has not started', function (): void {
    $registrar = app(LearnerRegistrar::class);
    $registrar->fromTelegramUser(['id' => 4005, 'first_name' => 'A', 'language_code' => 'ru']);

    $again = $registrar->fromTelegramUser(['id' => 4005, 'first_name' => 'A', 'language_code' => 'uz']);

    expect($again->interface_locale)->toBe('uz');
});

it('writes the acquisition source and start payload once, on the first contact that carries them', function (): void {
    $registrar = app(LearnerRegistrar::class);

    $learner = $registrar->fromTelegramUser(['id' => 4006, 'first_name' => 'A'], 'group');
    $registrar->fromTelegramUser(['id' => 4006, 'first_name' => 'A'], 'src:blog', 'src_blog');

    expect($learner->fresh()->acquisition_source)->toBe('group')
        // ...but a payload that arrived later is still recorded when none was stored.
        ->and($learner->fresh()->start_payload)->toBe('src_blog');

    $registrar->fromTelegramUser(['id' => 4006, 'first_name' => 'A'], 'ref', 'ref_other');

    expect($learner->fresh()->acquisition_source)->toBe('group')
        ->and($learner->fresh()->start_payload)->toBe('src_blog');
});

it('revives a soft-deleted learner', function (): void {
    $registrar = app(LearnerRegistrar::class);
    $learner = $registrar->fromTelegramUser(['id' => 4007, 'first_name' => 'A']);
    $learner->delete();

    $revived = $registrar->fromTelegramUser(['id' => 4007, 'first_name' => 'A']);

    expect($revived->id)->toBe($learner->id)
        ->and($revived->trashed())->toBeFalse()
        ->and(Learner::count())->toBe(1);
});

it('truncates over-long profile fields instead of failing', function (): void {
    $learner = app(LearnerRegistrar::class)->fromTelegramUser([
        'id' => 4008,
        'first_name' => str_repeat('я', 300),
        'username' => str_repeat('u', 300),
        'language_code' => 'ru-RU-extra-long-tag',
    ]);

    expect(mb_strlen((string) $learner->first_name))->toBe(100)
        ->and(strlen((string) $learner->username))->toBe(100)
        ->and(strlen((string) $learner->language_code))->toBe(10);
});

it('rejects a payload without a Telegram user id', function (): void {
    expect(fn () => app(LearnerRegistrar::class)->fromTelegramUser(['first_name' => 'A']))
        ->toThrow(InvalidArgumentException::class);
});
