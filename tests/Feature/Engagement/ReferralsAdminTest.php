<?php

declare(strict_types=1);

use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\ReferralsRelationManager;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Referrals on the learner page (docs/13 §11 2C.1): admin only, read-only, "Clear flag" rewards a parked
 * activated referral at once, "Reject" refuses it; both audited. The list column counts non-rejected referrals.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['referrals.reward_days' => 7]);
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->learner = Learner::factory()->create();
    $this->props = ['ownerRecord' => $this->learner, 'pageClass' => ViewLearner::class];
});

it('lists only the referrals this learner made', function (): void {
    $mine = Referral::factory()->create(['referrer_learner_id' => $this->learner->id]);
    $asReferee = Referral::factory()->create(['referee_learner_id' => $this->learner->id]);
    $foreign = Referral::factory()->create();

    $this->actingAs($this->admin);

    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$asReferee, $foreign]);
});

it('clears the flag of a flagged activated referral, which rewards both sides and is audited', function (): void {
    $flagged = Referral::factory()->activated()->flagged()->create(['referrer_learner_id' => $this->learner->id]);
    $clean = Referral::factory()->create(['referrer_learner_id' => $this->learner->id]);

    $this->actingAs($this->admin);

    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->assertTableActionVisible('unflag', $flagged)
        ->assertTableActionVisible('reject', $flagged)
        ->assertTableActionHidden('unflag', $clean)
        ->assertTableActionHidden('reject', $clean)
        ->callTableAction('unflag', $flagged);

    $flagged->refresh();
    expect($flagged->flagged_at)->toBeNull()
        ->and($flagged->status)->toBe('rewarded')
        ->and(Subscription::query()->where('subscriber_id', $this->learner->id)->where('provider', 'reward')->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $flagged->referee_learner_id)->where('provider', 'reward')->count())->toBe(1);

    $audit = AuditLog::query()->where('action', 'referral.unflagged')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($flagged->id);
});

it('rejects a flagged referral for good', function (): void {
    $flagged = Referral::factory()->flagged()->create(['referrer_learner_id' => $this->learner->id]);

    $this->actingAs($this->admin);

    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->callTableAction('reject', $flagged)
        ->assertTableActionHidden('reject', $flagged->fresh())
        ->assertTableActionHidden('unflag', $flagged->fresh());

    expect($flagged->fresh()?->status)->toBe('rejected')
        ->and($flagged->fresh()?->reject_reason)->toBe('flagged')
        ->and(AuditLog::query()->where('action', 'referral.rejected')->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(0);
});

it('is closed to a teacher', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    Referral::factory()->flagged()->create(['referrer_learner_id' => $this->learner->id]);

    $this->actingAs($teacher);

    expect(ReferralsRelationManager::canViewForRecord($this->learner, ViewLearner::class))->toBeFalse();
    Livewire::test(ReferralsRelationManager::class, $this->props)->assertForbidden();
});

it('shows the number of non-rejected referrals in the learners list', function (): void {
    Referral::factory()->count(2)->create(['referrer_learner_id' => $this->learner->id]);
    Referral::factory()->create(['referrer_learner_id' => $this->learner->id, 'status' => 'rejected', 'reject_reason' => 'self']);

    $this->actingAs($this->admin);

    Livewire::test(ListLearners::class)
        ->assertCanSeeTableRecords([$this->learner])
        ->assertTableColumnStateSet('referrals_count', 2, $this->learner);

    expect(LearnerResource::getUrl('view', ['record' => $this->learner]))->toContain((string) $this->learner->id);
});
