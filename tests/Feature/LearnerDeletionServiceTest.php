<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerDeletionService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Domain\Learners\Services\ReminderDispatcher;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeTelegramClient;

/*
 * "Delete account" (owner's decision of 03.11.2026), the service: the soft delete, one audit record without personal
 * data, the Stars renewal stopped at once (payments untouched, the paid period kept), idempotence, the way back.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 10:00:00', 'UTC'));
    config()->set('learners.purge.after_days', 30);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('soft-deletes the learner and writes one audit record: by the learner themselves', function (): void {
    $learner = Learner::factory()->create(['first_name' => 'Zoya', 'username' => 'zoya_k']);

    $deleted = app(LearnerDeletionService::class)->delete($learner, null, '203.0.113.7');

    $audit = AuditLog::query()->where('action', 'learner.deleted')->sole();
    expect($deleted)->toBeTrue()
        ->and($learner->trashed())->toBeTrue()
        ->and(Learner::query()->find($learner->id))->toBeNull()
        ->and(Learner::withTrashed()->find($learner->id)?->deleted_at)->not->toBeNull()
        ->and($audit->user_id)->toBeNull()
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->ip)->toBe('203.0.113.7')
        ->and($audit->meta)->toBe(['by' => 'self'])
        // no personal data in the record
        ->and(json_encode($audit->meta))->not->toContain('Zoya')->not->toContain('zoya_k');
});

it('names the admin in the audit record when support deletes the account', function (): void {
    $admin = User::factory()->create();
    $learner = Learner::factory()->create();

    app(LearnerDeletionService::class)->delete($learner, $admin);

    $audit = AuditLog::query()->where('action', 'learner.deleted')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->meta)->toBe(['by' => 'admin']);
});

it('is idempotent: a second call (double tap, stale in-memory copy) changes nothing and writes no second audit', function (): void {
    $service = app(LearnerDeletionService::class);
    $learner = Learner::factory()->create();
    $stale = Learner::query()->findOrFail($learner->id);

    expect($service->delete($learner))->toBeTrue()
        ->and($service->delete($stale))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(1);
});

it('says until when the learner can come back: deleted_at plus the purge delay', function (): void {
    config()->set('learners.purge.after_days', 14);
    $learner = Learner::factory()->create();

    app(LearnerDeletionService::class)->delete($learner);

    expect(app(LearnerDeletionService::class)->restorableUntil($learner)->toIso8601String())->toBe('2026-11-17T10:00:00+00:00');
});

it('stops the Stars auto-renewal at once but keeps the paid period and every payment', function (): void {
    /** @var FakeTelegramClient $telegram */
    $telegram = app(TelegramClient::class);
    config()->set('billing.providers.telegram_stars.enabled', true);
    $learner = Learner::factory()->create(['telegram_user_id' => 880_001]);
    $subscription = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'provider_ref' => 'ch_live',
        'meta' => ['stars' => ['charge_id' => 'ch_live', 'recurring' => true]],
    ]);
    $payment = Payment::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id]);

    app(LearnerDeletionService::class)->delete($learner);

    $fresh = $subscription->fresh();
    expect($telegram->starSubscriptionEdits)->toBe([['user_id' => 880_001, 'charge_id' => 'ch_live', 'is_canceled' => true]])
        ->and($fresh->status)->toBe('canceled')
        ->and($fresh->current_period_ends_at?->equalTo($subscription->current_period_ends_at))->toBeTrue()
        ->and(Payment::query()->whereKey($payment->id)->exists())->toBeTrue();
});

it('deletes the account even when Telegram refuses to stop the renewal', function (): void {
    /** @var FakeTelegramClient $telegram */
    $telegram = app(TelegramClient::class);
    config()->set('billing.providers.telegram_stars.enabled', true);
    $learner = Learner::factory()->create(['telegram_user_id' => 880_002]);
    $subscription = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => 'telegram_stars',
        'provider_ref' => 'ch_bad',
        'meta' => ['stars' => ['charge_id' => 'ch_bad', 'recurring' => true]],
    ]);
    $telegram->failStarsOn = 'ch_bad';

    expect(app(LearnerDeletionService::class)->delete($learner))->toBeTrue()
        ->and(Learner::query()->find($learner->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'learner.deleted')->count())->toBe(1)
        ->and($subscription->fresh()->status)->toBe('active');
});

it('lets the learner come back with their own /start within the delay, with the data as it was', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_003, 'daily_goal' => 35]);
    app(LearnerDeletionService::class)->delete($learner);

    $back = app(LearnerRegistrar::class)->fromTelegramUser(['id' => 880_003, 'first_name' => 'Ali']);

    expect($back->id)->toBe($learner->id)
        ->and($back->trashed())->toBeFalse()
        ->and(Learner::query()->find($learner->id)?->daily_goal)->toBe(35);
});

it('sends no reminder to a deleted learner', function (): void {
    $learner = Learner::factory()->create([
        'reminder_time' => '09:00',
        'next_reminder_at' => now()->subMinute(),
    ]);
    // The control: a live learner due at the same moment is claimed.
    Learner::factory()->create(['reminder_time' => '09:00', 'next_reminder_at' => now()->subMinute()]);
    app(LearnerDeletionService::class)->delete($learner);

    $report = app(ReminderDispatcher::class)->dispatchDue(CarbonImmutable::now());

    expect($report->claimed)->toBe(1);
});
