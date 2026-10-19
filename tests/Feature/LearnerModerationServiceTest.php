<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerModerationService;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('blocks a learner and writes one audit record', function (): void {
    $actor = User::factory()->create();
    $learner = Learner::factory()->create();

    $changed = app(LearnerModerationService::class)->block($learner, $actor, '203.0.113.7');

    $audit = AuditLog::query()->where('action', 'learner.blocked')->sole();
    expect($changed)->toBeTrue()
        ->and($learner->blocked_at)->not->toBeNull()
        ->and($learner->fresh()->blocked_at)->not->toBeNull()
        ->and($audit->user_id)->toBe($actor->id)
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->ip)->toBe('203.0.113.7');
});

it('is idempotent: a repeated block or unblock changes nothing and writes no second audit', function (): void {
    $service = app(LearnerModerationService::class);
    $actor = User::factory()->create();
    $learner = Learner::factory()->create();

    $service->block($learner, $actor);
    $blockedAt = $learner->fresh()->blocked_at;

    // A stale in-memory copy (a second click on an old table row) must not pass the check.
    $stale = Learner::query()->findOrFail($learner->id)->setAttribute('blocked_at', null);

    expect($service->block($stale, $actor))->toBeFalse()
        ->and($service->block($learner, $actor))->toBeFalse()
        ->and($learner->fresh()->blocked_at?->equalTo($blockedAt))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'learner.blocked')->count())->toBe(1);

    expect($service->unblock($learner, $actor))->toBeTrue()
        ->and($learner->fresh()->blocked_at)->toBeNull()
        ->and($service->unblock($learner, $actor))->toBeFalse()
        ->and(AuditLog::query()->where('action', 'learner.unblocked')->count())->toBe(1);
});

it('does nothing for a learner that was never blocked when unblocking', function (): void {
    $learner = Learner::factory()->create();

    expect(app(LearnerModerationService::class)->unblock($learner, User::factory()->create()))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});
