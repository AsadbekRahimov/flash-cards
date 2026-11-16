<?php

declare(strict_types=1);

use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\ReferralsRelationManager;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Referral;
use App\Models\ReferralMilestone;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Referrals on the learner page (docs/13 §11 2C.1): admin only, read-only, "Clear flag" counts a parked
 * activated referral at once, "Reject" refuses it; both audited. The list column counts non-rejected referrals.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

it('clears the flag of a flagged activated referral, which counts it for the inviter (here: the 3rd friend, +7 days) and is audited', function (): void {
    config(['referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);
    Referral::factory()->count(2)->rewarded()->create(['referrer_learner_id' => $this->learner->id]);
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
        ->and(ReferralMilestone::query()->where('referrer_learner_id', $this->learner->id)->where('milestone', 3)->whereNotNull('granted_at')->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $this->learner->id)->where('provider', 'reward')->count())->toBe(1)
        // The invited learner gets nothing by the referral (the welcome trial is every new learner's, not the referral's).
        ->and(Subscription::query()->where('subscriber_id', $flagged->referee_learner_id)->count())->toBe(0);

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

it('summarizes the milestones of the inviter above the table: reached, granted until a date, owed, skipped', function (): void {
    config(['referrals.milestones' => [3, 6], 'referrals.milestone_days' => 7]);
    Referral::factory()->count(3)->rewarded()->create(['referrer_learner_id' => $this->learner->id]);
    $grant = Subscription::factory()->create([
        'subscriber_id' => $this->learner->id,
        'provider' => 'reward',
        'current_period_ends_at' => CarbonImmutable::parse('2026-12-01 10:00:00'),
    ]);
    ReferralMilestone::factory()->granted(subscriptionId: $grant->id)->create(['referrer_learner_id' => $this->learner->id, 'milestone' => 3, 'reached_at' => '2026-11-20 09:00:00']);
    ReferralMilestone::factory()->granted('perpetual')->create(['referrer_learner_id' => $this->learner->id, 'milestone' => 6, 'reached_at' => '2026-11-25 09:00:00']);

    $this->actingAs($this->admin);

    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->assertSee('Награды за приглашения')
        ->assertSee('Веха «3 засчитанных друзей»: +7 дн. Pro, достигнута 20.11.2026 — выдано, подписка до 01.12.2026')
        ->assertSee('Веха «6 засчитанных друзей»: +7 дн. Pro, достигнута 25.11.2026 — не выдано (у ученика бессрочная подписка)');
});

it('says that no milestone is reached yet, with the number of counted friends, and shows an owed reward as such', function (): void {
    Referral::factory()->count(2)->rewarded()->create(['referrer_learner_id' => $this->learner->id]);
    $this->actingAs($this->admin);

    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->assertSee('Засчитанных друзей пока 2 — ни одна веха ещё не достигнута.');

    ReferralMilestone::factory()->create(['referrer_learner_id' => $this->learner->id, 'milestone' => 3, 'reached_at' => '2026-11-20 09:00:00']);

    Livewire::test(ReferralsRelationManager::class, $this->props)->assertSee('награда ещё не выдана');
});

it('opens the learner page with the referrals tab and the milestones, without the removed per-friend rewards', function (): void {
    ReferralMilestone::factory()->granted('learner_blocked')->create(['referrer_learner_id' => $this->learner->id, 'milestone' => 3]);
    $this->actingAs($this->admin);

    Livewire::test(ViewLearner::class, ['record' => $this->learner->getKey()])->assertSuccessful();
    Livewire::test(ReferralsRelationManager::class, $this->props)
        ->assertSee('не выдано (ученик заблокирован)')
        ->assertDontSee('Награждён');
});

it('labels the trial provider and the counted status in Russian', function (): void {
    expect(__('admin.common.provider.trial'))->toBe('Пробный')
        ->and(__('admin.learners.referrals.status.rewarded'))->toBe('Засчитан');
});
