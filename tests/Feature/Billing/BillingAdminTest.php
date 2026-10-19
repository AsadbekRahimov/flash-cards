<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Billing\Services\PromoCodeAdminService;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\OrganizationResource\Pages\CreateOrganization;
use App\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Filament\Resources\OrganizationResource\RelationManagers\GroupsRelationManager;
use App\Filament\Resources\PromoCodeResource;
use App\Filament\Resources\PromoCodeResource\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodeResource\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodeResource\Pages\ListPromoCodes;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->teacher = User::factory()->create(['is_active' => true]);
    $this->teacher->assignRole('teacher');
});

// --- access ----------------------------------------------------------------

it('closes every billing screen to teachers and inactive admins', function (): void {
    foreach (['subscriptions', 'promo-codes', 'organizations'] as $path) {
        $this->actingAs($this->teacher)->get('/admin/'.$path)->assertForbidden();
    }

    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');
    $this->actingAs($inactive)->get('/admin/subscriptions')->assertForbidden();

    $this->actingAs($this->teacher);
    expect(SubscriptionResource::canViewAny())->toBeFalse()
        ->and(PromoCodeResource::canViewAny())->toBeFalse()
        ->and(OrganizationResource::canViewAny())->toBeFalse();
});

it('does not let a teacher mount the pages or call the actions through Livewire', function (): void {
    $learner = Learner::factory()->create();
    $subscription = Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $this->actingAs($this->teacher);

    Livewire::test(ListSubscriptions::class)->assertForbidden();
    Livewire::test(ListPromoCodes::class)->assertForbidden();
    Livewire::test(CreatePromoCode::class)->assertForbidden();
    Livewire::test(ListOrganizations::class)->assertForbidden();
    Livewire::test(CreateOrganization::class)->assertForbidden();

    expect($subscription->fresh()?->status)->toBe('active')
        ->and(AuditLog::query()->count())->toBe(0);
});

it('lists subscriptions of learners and organizations for an admin', function (): void {
    $learner = Learner::factory()->create(['first_name' => 'Zinaida', 'last_name' => null, 'username' => 'zina']);
    $org = Organization::factory()->create(['name' => 'Bright Center']);
    $mine = Subscription::factory()->create(['subscriber_id' => $learner->id, 'provider' => 'promo']);
    $sponsor = Subscription::factory()->forOrganization($org)->create();
    $ended = Subscription::factory()->expired()->create(['subscriber_id' => $learner->id]);

    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine, $sponsor, $ended])
        ->assertSee('Zinaida @zina')
        ->assertSee('Bright Center')
        ->filterTable('subscriber_type', 'organization')
        ->assertCanSeeTableRecords([$sponsor])
        ->assertCanNotSeeTableRecords([$mine, $ended])
        ->resetTableFilters()
        ->filterTable('live', true)
        ->assertCanSeeTableRecords([$mine, $sponsor])
        ->assertCanNotSeeTableRecords([$ended])
        ->resetTableFilters()
        ->filterTable('provider', 'promo')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$sponsor]);
});

// --- Grant / Revoke -----------------------------------------------------------

it('grants Pro to a learner with an end date, writes the audit record and invalidates the cache', function (): void {
    $learner = Learner::factory()->create();
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($learner)->plan)->toBe(Plan::Free);   // primes the cache
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', [
            'subscriber_type' => 'learner',
            'learner_id' => $learner->id,
            'perpetual' => false,
            'until' => now()->addDays(30)->toDateTimeString(),
            'note' => 'Olympiad winner',
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::query()->where('subscriber_id', $learner->id)->sole();
    expect($subscription->subscriber_type)->toBe('learner')
        ->and($subscription->plan)->toBe('pro')
        ->and($subscription->provider)->toBe('manual')
        ->and($subscription->granted_by_user_id)->toBe($this->admin->id)
        ->and($subscription->note)->toBe('Olympiad winner')
        ->and($subscription->current_period_ends_at)->not->toBeNull()
        ->and($resolver->for($learner)->plan)->toBe(Plan::Pro);

    $audit = AuditLog::query()->where('action', 'subscription.granted')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($subscription->id)
        ->and($audit->meta['note'])->toBe('Olympiad winner')
        ->and($audit->meta['subscriber_id'])->toBe($learner->id);
});

it('grants a perpetual Pro to an organization', function (): void {
    $org = Organization::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', [
            'subscriber_type' => 'organization',
            'organization_id' => $org->id,
            'perpetual' => true,
            'note' => 'Contract #12',
        ])
        ->assertHasNoActionErrors();

    $subscription = Subscription::query()->sole();
    expect($subscription->subscriber_type)->toBe('organization')
        ->and($subscription->subscriber_id)->toBe($org->id)
        ->and($subscription->current_period_ends_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'subscription.granted')->count())->toBe(1);
});

it('requires a reason, an existing subject and a future end date', function (): void {
    $learner = Learner::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => true, 'note' => ''])
        ->assertHasActionErrors(['note' => 'required']);
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'perpetual' => true, 'note' => 'x'])
        ->assertHasActionErrors(['learner_id' => 'required']);

    // A date in the past and a subject that does not exist are stopped by the form itself.
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', [
            'subscriber_type' => 'learner',
            'learner_id' => $learner->id,
            'perpetual' => false,
            'until' => now()->subDay()->toDateTimeString(),
            'note' => 'late',
        ])
        ->assertHasActionErrors(['until']);
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => 999999, 'perpetual' => true, 'note' => 'ghost'])
        ->assertHasActionErrors(['learner_id']);

    expect(Subscription::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('revokes a live subscription with a reason; the cache is invalidated and the audit record written', function (): void {
    $learner = Learner::factory()->create();
    $subscription = Subscription::factory()->create(['subscriber_id' => $learner->id]);
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($learner)->plan)->toBe(Plan::Pro);
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callTableAction('revoke', $subscription, ['note' => 'Refund'])
        ->assertHasNoTableActionErrors();

    expect($subscription->fresh()?->status)->toBe('expired')
        ->and($resolver->for($learner)->plan)->toBe(Plan::Free);

    $audit = AuditLog::query()->where('action', 'subscription.revoked')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->meta['note'])->toBe('Refund');
});

it('requires a reason to revoke and hides Revoke on an ended subscription', function (): void {
    $live = Subscription::factory()->create();
    $ended = Subscription::factory()->expired()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callTableAction('revoke', $live, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);
    Livewire::test(ListSubscriptions::class)
        ->assertTableActionHidden('revoke', $ended);

    expect($live->fresh()?->status)->toBe('active');
});

// --- promo codes ------------------------------------------------------------------

it('offers a generated code and stores it normalized with the creator and an audit record', function (): void {
    $this->actingAs($this->admin);

    $component = Livewire::test(CreatePromoCode::class);
    $generated = $component->get('data.code');
    expect($generated)->toMatch('/\A[A-Z0-9]{10}\z/');

    $component
        ->fillForm([
            'code' => ' summer-2026 ',
            'plan' => 'pro',
            'duration_days' => 90,
            'max_redemptions' => 50,
            'is_active' => true,
            'note' => 'Blogger campaign',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $promo = PromoCode::query()->sole();
    expect($promo->code)->toBe('SUMMER2026')
        ->and($promo->duration_days)->toBe(90)
        ->and($promo->max_redemptions)->toBe(50)
        ->and($promo->redemptions_count)->toBe(0)
        ->and($promo->created_by_user_id)->toBe($this->admin->id);

    $audit = AuditLog::query()->where('action', 'promo.created')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_type)->toBe(PromoCode::class)
        ->and($audit->entity_id)->toBe($promo->id)
        ->and($audit->meta['code'])->toBe('SUMMER2026')
        ->and($audit->meta['duration_days'])->toBe(90);
});

it('rejects a duplicate code in any spelling, a malformed code and bad numbers', function (): void {
    PromoCode::factory()->create(['code' => 'TAKEN123']);
    $this->actingAs($this->admin);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'taken-123', 'plan' => 'pro', 'duration_days' => 30])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'ab!', 'plan' => 'pro', 'duration_days' => 30])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'GOOD1234', 'plan' => 'pro', 'duration_days' => 0, 'max_redemptions' => 0])
        ->call('create')
        ->assertHasFormErrors(['duration_days', 'max_redemptions']);

    expect(PromoCode::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('does not offer Free as a promo plan', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'FREEPLAN', 'plan' => 'free', 'duration_days' => 30])
        ->call('create')
        ->assertHasFormErrors(['plan']);
});

it('shows the redemption counter read-only and keeps the code and counter on edit', function (): void {
    $promo = PromoCode::factory()->create(['code' => 'KEEPME', 'redemptions_count' => 7, 'max_redemptions' => 10]);
    $this->actingAs($this->admin);

    Livewire::test(EditPromoCode::class, ['record' => $promo->getKey()])
        ->assertFormSet(['code' => 'KEEPME', 'redemptions_count' => 7])
        ->fillForm(['code' => 'HACKED', 'redemptions_count' => 0, 'duration_days' => 45, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $promo->refresh();
    expect($promo->code)->toBe('KEEPME')
        ->and($promo->redemptions_count)->toBe(7)
        ->and($promo->duration_days)->toBe(45)
        ->and($promo->is_active)->toBeFalse();
});

it('lists codes with the counter and lets a redeemed code only be deactivated, not deleted', function (): void {
    $used = PromoCode::factory()->create(['code' => 'USEDCODE', 'redemptions_count' => 2, 'max_redemptions' => 5]);
    $unused = PromoCode::factory()->create(['code' => 'UNUSED01']);
    $this->actingAs($this->admin);

    Livewire::test(ListPromoCodes::class)
        ->assertCanSeeTableRecords([$used, $unused])
        ->assertSee('2 / 5')
        ->assertTableActionHidden('delete', $used)
        ->callTableAction('delete', $unused);

    expect(PromoCode::query()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(PromoCode::query()->whereKey($used->id)->exists())->toBeTrue();
});

// --- organizations ----------------------------------------------------------------------

it('creates an organization and validates the slug and the time zone', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreateOrganization::class)
        ->fillForm(['name' => 'Bright Center', 'slug' => 'Not Valid', 'type' => 'center', 'status' => 'active', 'timezone' => 'Mars/Base', 'locale' => 'ru'])
        ->call('create')
        ->assertHasFormErrors(['slug', 'timezone']);

    Livewire::test(CreateOrganization::class)
        ->fillForm(['name' => 'Bright Center', 'slug' => 'bright-center', 'type' => 'center', 'status' => 'active', 'timezone' => 'Asia/Tashkent', 'locale' => 'uz'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Organization::query()->sole()->slug)->toBe('bright-center');
});

it('linking a group through the relation manager gives its members sponsored Pro at once, unlinking takes it away', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null]);
    $group = TelegramGroup::factory()->create(['status' => 'active', 'title' => 'Evening class']);
    $member = Learner::factory()->memberOf($group)->create();
    $resolver = app(EntitlementResolver::class);

    expect($resolver->for($member)->plan)->toBe(Plan::Free);   // primes the cache
    $this->actingAs($this->admin);

    $manager = Livewire::test(GroupsRelationManager::class, ['ownerRecord' => $org, 'pageClass' => OrganizationResource\Pages\EditOrganization::class]);

    $manager->callTableAction('linkGroup', data: ['telegram_group_id' => $group->id])->assertHasNoTableActionErrors();

    expect($group->fresh()?->organization_id)->toBe($org->id)
        ->and($resolver->for($member)->plan)->toBe(Plan::Pro)
        ->and($resolver->for($member)->source)->toBe('sponsor:organization:'.$org->id);

    $audit = AuditLog::query()->where('action', 'organization.linked_group')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_id)->toBe($org->id)
        ->and($audit->meta['telegram_group_id'])->toBe($group->id);

    $manager->callTableAction('unlinkGroup', $group->fresh());

    expect($group->fresh()?->organization_id)->toBeNull()
        ->and($resolver->for($member)->plan)->toBe(Plan::Free)
        ->and(AuditLog::query()->where('action', 'organization.unlinked_group')->count())->toBe(1);
});

it('does not take a group away from another organization', function (): void {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $first->id]);
    $this->actingAs($this->admin);

    Livewire::test(GroupsRelationManager::class, ['ownerRecord' => $second, 'pageClass' => OrganizationResource\Pages\EditOrganization::class])
        ->callTableAction('linkGroup', data: ['telegram_group_id' => $group->id])
        ->assertNotified('Group not linked');

    expect($group->fresh()?->organization_id)->toBe($first->id)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('suspending an organization stops the sponsorship at once (observer drops the cache)', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null]);
    $member = Learner::factory()->seatedIn($org)->create(); // a seat sponsors since 2B.1 (docs/13 §1.3)
    $resolver = app(EntitlementResolver::class);
    expect($resolver->for($member)->plan)->toBe(Plan::Pro);
    $this->actingAs($this->admin);

    Livewire::test(OrganizationResource\Pages\EditOrganization::class, ['record' => $org->getKey()])
        ->fillForm(['status' => 'suspended'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($resolver->for($member)->plan)->toBe(Plan::Free);
});

it('does not let a teacher link a group through a direct Livewire call', function (): void {
    $org = Organization::factory()->create();
    $free = TelegramGroup::factory()->create();
    $this->actingAs($this->teacher);

    $manager = Livewire::test(GroupsRelationManager::class, ['ownerRecord' => $org, 'pageClass' => OrganizationResource\Pages\EditOrganization::class]);

    try {
        $manager->callTableAction('linkGroup', data: ['telegram_group_id' => $free->id]);
    } catch (Throwable) {
        // an unauthorized or hidden action may also fail loudly — what matters is the data below
    }

    expect($free->fresh()?->organization_id)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('does not let a teacher unlink a group through a direct Livewire call', function (): void {
    $org = Organization::factory()->create();
    $linked = TelegramGroup::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($this->teacher);

    $manager = Livewire::test(GroupsRelationManager::class, ['ownerRecord' => $org, 'pageClass' => OrganizationResource\Pages\EditOrganization::class]);

    try {
        $manager->callTableAction('unlinkGroup', $linked);
    } catch (Throwable) {
        // an unauthorized or hidden action may also fail loudly — what matters is the data below
    }

    expect($linked->fresh()?->organization_id)->toBe($org->id)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('audits an edit of a promo code with a from / to diff of the changed fields only', function (): void {
    $promo = PromoCode::factory()->create([
        'code' => 'EDITME', 'max_redemptions' => 10, 'duration_days' => 30, 'expires_at' => null, 'is_active' => true,
    ]);
    $this->actingAs($this->admin);

    Livewire::test(EditPromoCode::class, ['record' => $promo->getKey()])
        ->fillForm(['max_redemptions' => 25, 'duration_days' => 30, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($promo->fresh()?->max_redemptions)->toBe(25)
        ->and($promo->fresh()?->is_active)->toBeFalse();

    $audit = AuditLog::query()->where('action', 'promo.updated')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->entity_type)->toBe(PromoCode::class)
        ->and($audit->entity_id)->toBe($promo->id)
        ->and($audit->meta['code'])->toBe('EDITME')
        // toEqual: jsonb on PostgreSQL does not keep the key order
        ->and($audit->meta['changes'])->toEqual([
            'max_redemptions' => ['from' => 10, 'to' => 25],
            'is_active' => ['from' => true, 'to' => false],
        ]);
});

it('records the old and new expiry of a promo code as ISO dates and writes nothing for a no-op save', function (): void {
    $promo = PromoCode::factory()->create(['expires_at' => now()->addDays(5)->startOfSecond()]);
    $old = $promo->expires_at?->toIso8601String();
    $new = now()->addDays(40)->startOfSecond();

    $service = app(PromoCodeAdminService::class);
    $service->update($promo, ['expires_at' => $new], $this->admin, '10.0.0.7');

    $audit = AuditLog::query()->where('action', 'promo.updated')->sole();
    expect($audit->ip)->toBe('10.0.0.7')
        ->and($audit->meta['changes']['expires_at'])->toEqual(['from' => $old, 'to' => $new->toIso8601String()]);

    // Same values again, and keys that are not editable (code, plan, counter): nothing changes, nothing is logged.
    $service->update($promo->fresh(), ['expires_at' => $new, 'code' => 'HACKED', 'plan' => 'free', 'redemptions_count' => 99], $this->admin);

    $fresh = $promo->fresh();
    expect($fresh?->code)->not->toBe('HACKED')
        ->and($fresh?->redemptions_count)->toBe(0)
        ->and(AuditLog::query()->where('action', 'promo.updated')->count())->toBe(1);
});

it('audits a status change of an organization (suspend and activate), but not other edits', function (): void {
    $org = Organization::factory()->create(['name' => 'Bright Center', 'status' => 'active']);
    $this->actingAs($this->admin);

    $page = Livewire::test(OrganizationResource\Pages\EditOrganization::class, ['record' => $org->getKey()]);

    $page->fillForm(['name' => 'Bright Center 2'])->call('save')->assertHasNoFormErrors();
    expect(AuditLog::query()->count())->toBe(0);

    $page->fillForm(['status' => 'suspended'])->call('save')->assertHasNoFormErrors();
    $page->fillForm(['status' => 'active'])->call('save')->assertHasNoFormErrors();

    $audits = AuditLog::query()->where('action', 'organization.status_changed')->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->user_id)->toBe($this->admin->id)
        ->and($audits[0]->entity_type)->toBe(Organization::class)
        ->and($audits[0]->entity_id)->toBe($org->id)
        ->and($audits[0]->meta['from'])->toBe('active')
        ->and($audits[0]->meta['to'])->toBe('suspended')
        ->and($audits[1]->meta['from'])->toBe('suspended')
        ->and($audits[1]->meta['to'])->toBe('active')
        ->and($org->fresh()?->name)->toBe('Bright Center 2');
});

it('does not delete an organization with a live subscription or linked groups and says why', function (): void {
    $withSubscription = Organization::factory()->create();
    Subscription::factory()->forOrganization($withSubscription)->create(['current_period_ends_at' => null]);
    $withGroup = Organization::factory()->create();
    TelegramGroup::factory()->create(['organization_id' => $withGroup->id]);
    $this->actingAs($this->admin);

    expect(Gate::forUser($this->admin)->inspect('delete', $withSubscription)->message())->toContain('live subscription')
        ->and(Gate::forUser($this->admin)->inspect('delete', $withGroup)->message())->toContain('linked groups');

    Livewire::test(ListOrganizations::class)
        ->assertCanSeeTableRecords([$withSubscription, $withGroup])
        ->assertTableActionVisible('delete', $withSubscription)   // visible, with the reason as its tooltip
        ->callTableAction('delete', $withSubscription)
        ->assertNotified()
        ->callTableAction('delete', $withGroup);

    expect(Organization::query()->whereKey([$withSubscription->id, $withGroup->id])->count())->toBe(2);
});

it('deletes an organization that has neither a live subscription nor groups', function (): void {
    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->expired()->create();
    $this->actingAs($this->admin);

    expect(Gate::forUser($this->admin)->allows('delete', $org))->toBeTrue();

    Livewire::test(ListOrganizations::class)->callTableAction('delete', $org);

    expect(Organization::query()->whereKey($org->id)->exists())->toBeFalse();
});

it('never lets a teacher delete an organization', function (): void {
    $org = Organization::factory()->create();

    expect(Gate::forUser($this->teacher)->allows('delete', $org))->toBeFalse();
});

it('reads the promo expiry as local time (default learner time zone) and stores it as UTC', function (): void {
    $this->actingAs($this->admin);
    expect(config('learners.default_timezone'))->toBe('Asia/Tashkent');   // UTC+5, no DST

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'TZPROMO1', 'plan' => 'pro', 'duration_days' => 30, 'expires_at' => '2031-06-01 12:00:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    $promo = PromoCode::query()->where('code', 'TZPROMO1')->sole();
    expect($promo->expires_at?->utc()->format('Y-m-d H:i:s'))->toBe('2031-06-01 07:00:00');
    expect(DB::table('promo_codes')->where('id', $promo->id)->value('expires_at'))->toStartWith('2031-06-01 07:00:00');

    // The edit form shows the stored UTC moment in local time again.
    expect(Livewire::test(EditPromoCode::class, ['record' => $promo->getKey()])->get('data.expires_at'))->toBe('2031-06-01 12:00:00');
});

it('reads the Grant Pro end date as local time and stores it as UTC; a past local moment is refused', function (): void {
    $learner = Learner::factory()->create();
    $this->actingAs($this->admin);

    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => false, 'until' => '2031-06-01 12:00:00', 'note' => 'tz'])
        ->assertHasNoActionErrors();

    expect(Subscription::query()->where('subscriber_id', $learner->id)->sole()->current_period_ends_at?->utc()->format('Y-m-d H:i:s'))->toBe('2031-06-01 07:00:00');

    // One hour ago in real time, written as local (UTC+5) wall-clock time: still in the past.
    $past = now()->subHour()->setTimezone('Asia/Tashkent')->format('Y-m-d H:i:s');
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => false, 'until' => $past, 'note' => 'late'])
        ->assertHasActionErrors(['until']);

    // Two hours ahead in real time, as local wall-clock time: accepted.
    $soon = now()->addHours(2)->setTimezone('Asia/Tashkent')->format('Y-m-d H:i:s');
    $other = Learner::factory()->create();
    Livewire::test(ListSubscriptions::class)
        ->callAction('grantPro', ['subscriber_type' => 'learner', 'learner_id' => $other->id, 'perpetual' => false, 'until' => $soon, 'note' => 'soon'])
        ->assertHasNoActionErrors();
});

it('requires a promo code of at least 6 characters', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'AB-CDE', 'plan' => 'pro', 'duration_days' => 30])   // 5 after normalizing
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreatePromoCode::class)
        ->fillForm(['code' => 'AB-CDEF', 'plan' => 'pro', 'duration_days' => 30])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PromoCode::query()->sole()->code)->toBe('ABCDEF');
});
