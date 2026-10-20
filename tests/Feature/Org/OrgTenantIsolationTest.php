<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrganizationStaffService;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Org\Pages\OrgSettings;
use App\Filament\Org\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Org\Resources\InviteResource\Pages\ListInvites;
use App\Filament\Org\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Org\Resources\StaffResource\Pages\ListStaff;
use App\Filament\Org\Widgets\OrgActivityWidget;
use App\Filament\Org\Widgets\OrgSeatsWidget;
use App\Mail\OrgStaffInvitationMail;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;

/*
 * Tenant isolation and the CRUD of the `/org` cabinet (docs/13 §6.3, §10, DoD 2B.3): two centers,
 * A and B, with their own groups, members, invites and staff. Nothing of B is reachable from A's
 * cabinet — not in the lists, not by a direct record URL, not through an action. A teacher sees
 * only their groups and their learners, read-only.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    $this->ownerA = isoStaff($this->a, OrganizationRole::Owner);
    $this->ownerB = isoStaff($this->b, OrganizationRole::Owner);
    $this->teacherA = isoStaff($this->a, OrganizationRole::Teacher, twoFactor: false);

    $this->groupA1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'A one', 'status' => 'active']);
    $this->groupA2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'title' => 'A two', 'status' => 'active']);
    $this->groupB = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'title' => 'B one', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->groupA1->id, ['is_primary' => true]);

    $this->learnerA1 = Learner::factory()->memberOf($this->groupA1)->seatedIn($this->a)->create(['first_name' => 'Anna', 'username' => 'anna_a']);
    $this->learnerA2 = Learner::factory()->memberOf($this->groupA2)->seatedIn($this->a)->create(['first_name' => 'Aziz', 'username' => 'aziz_a']);
    $this->learnerB = Learner::factory()->memberOf($this->groupB)->seatedIn($this->b)->create(['first_name' => 'Bob', 'username' => 'bob_b']);
    $this->memberA1 = OrganizationMember::query()->where('learner_id', $this->learnerA1->id)->firstOrFail();
    $this->memberA2 = OrganizationMember::query()->where('learner_id', $this->learnerA2->id)->firstOrFail();
    $this->memberB = OrganizationMember::query()->where('learner_id', $this->learnerB->id)->firstOrFail();

    $this->inviteA = OrganizationInvite::factory()->create(['organization_id' => $this->a->id]);
    $this->inviteB = OrganizationInvite::factory()->create(['organization_id' => $this->b->id]);
});

function isoStaff(Organization $organization, OrganizationRole $role, bool $twoFactor = true): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => $twoFactor ? 'stub' : null,
        'two_factor_recovery_codes' => $twoFactor ? [] : null,
        'two_factor_confirmed_at' => $twoFactor ? now() : null,
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** Puts the test into the cabinet of $tenant as $user, as the panel middleware would. */
function isoEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.passed_at', now()->timestamp);
    Session::put('2fa.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

it('lists only the tenant records in every resource of the owner', function (): void {
    isoEnter($this->ownerA, $this->a);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$this->memberA1, $this->memberA2])
        ->assertCanNotSeeTableRecords([$this->memberB]);
    Livewire::test(ListGroups::class)
        ->assertCanSeeTableRecords([$this->groupA1, $this->groupA2])
        ->assertCanNotSeeTableRecords([$this->groupB]);
    Livewire::test(ListInvites::class)
        ->assertCanSeeTableRecords([$this->inviteA])
        ->assertCanNotSeeTableRecords([$this->inviteB]);
    Livewire::test(ListStaff::class)
        ->assertCountTableRecords(2)
        ->assertCanNotSeeTableRecords(OrganizationUser::query()->where('organization_id', $this->b->id)->get());
});

it('answers 404 to a direct URL of a record of the other organization', function (): void {
    $this->actingAs($this->ownerA, 'org')->withSession(['2fa.passed_at' => now()->timestamp, '2fa.user_id' => $this->ownerA->id]);

    $this->get("/org/center-a/members/{$this->memberA1->id}")->assertOk();
    $this->get("/org/center-a/members/{$this->memberB->id}")->assertNotFound();
    $this->get("/org/center-a/groups/{$this->groupA1->id}")->assertOk();
    $this->get("/org/center-a/groups/{$this->groupB->id}")->assertNotFound();
    $this->get("/org/center-b/groups/{$this->groupB->id}")->assertNotFound();
    $this->get("/org/center-b/members/{$this->memberB->id}")->assertNotFound();
});

it('refuses actions on records of the other organization even when called directly', function (): void {
    isoEnter($this->ownerA, $this->a);

    // The record is not in the tenant query, so the action cannot even be resolved.
    expect(fn () => Livewire::test(ListMembers::class)->callTableAction('remove', $this->memberB))->toThrow(ActionNotResolvableException::class);
    expect($this->memberB->fresh()?->status)->toBe('active');

    expect(fn () => Livewire::test(ListInvites::class)->callTableAction('deactivate', $this->inviteB))->toThrow(ActionNotResolvableException::class);
    expect($this->inviteB->fresh()?->is_active)->toBeTrue();

    expect(fn () => Livewire::test(ListGroups::class)->callTableAction('unlink', $this->groupB))->toThrow(ActionNotResolvableException::class);
    expect($this->groupB->fresh()?->organization_id)->toBe($this->b->id);
});

it('shows a teacher only the assigned groups and their learners, without the owner actions', function (): void {
    isoEnter($this->teacherA, $this->a);

    Livewire::test(ListGroups::class)
        ->assertCanSeeTableRecords([$this->groupA1])
        ->assertCanNotSeeTableRecords([$this->groupA2, $this->groupB])
        ->assertTableActionHidden('unlink', $this->groupA1);

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$this->memberA1])
        ->assertCanNotSeeTableRecords([$this->memberA2, $this->memberB])
        ->assertTableActionHidden('remove', $this->memberA1);

    $scope = app(OrgTenantScope::class);
    expect($scope->learners($this->teacherA, $this->a)->pluck('id')->all())->toBe([$this->learnerA1->id]);
    expect($scope->invites($this->teacherA, $this->a)->count())->toBe(0);
    expect($scope->staff($this->teacherA, $this->a)->count())->toBe(0);
    expect($scope->groups($this->ownerB, $this->a)->count())->toBe(0);

    expect(OrgSeatsWidget::canView())->toBeFalse();
    Livewire::test(OrgSeatsWidget::class)->assertForbidden();
    Livewire::test(OrgActivityWidget::class)->assertOk();
});

it('renders the owner dashboard widgets with the seats of the tenant', function (): void {
    isoEnter($this->ownerA, $this->a);

    expect(OrgSeatsWidget::canView())->toBeTrue();
    Livewire::test(OrgSeatsWidget::class)->assertOk()->assertSee('2 / 30');
    Livewire::test(OrgActivityWidget::class)->assertOk();
});

it('removes and adds members through the membership service with an audit record', function (): void {
    isoEnter($this->ownerA, $this->a);

    Livewire::test(ListMembers::class)->callTableAction('remove', $this->memberA1)->assertNotified();
    expect($this->memberA1->fresh()?->status)->toBe('removed')
        ->and(AuditLog::query()->where('action', 'organization.member_removed')->where('user_id', $this->ownerA->id)->exists())->toBeTrue();

    $newcomer = Learner::factory()->create(['username' => 'New_Comer', 'telegram_user_id' => 555_000_111]);

    Livewire::test(ListMembers::class)->callTableAction('add', data: ['telegram' => '@new_comer'])->assertNotified();
    expect(OrganizationMember::query()->where('organization_id', $this->a->id)->where('learner_id', $newcomer->id)->value('status'))->toBe('active');

    // Unknown Telegram: nothing is created, no learner of the platform is listed.
    Livewire::test(ListMembers::class)->callTableAction('add', data: ['telegram' => '@nobody_here'])->assertNotified();
    expect(OrganizationMember::query()->where('organization_id', $this->a->id)->count())->toBe(3);

    // A learner of center B can be added to A by its exact Telegram id (a learner may belong to several centers).
    Livewire::test(ListMembers::class)->callTableAction('add', data: ['telegram' => (string) $this->learnerB->telegram_user_id]);
    expect(OrganizationMember::query()->where('organization_id', $this->a->id)->where('learner_id', $this->learnerB->id)->exists())->toBeTrue()
        ->and($this->memberB->fresh()?->organization_id)->toBe($this->b->id);
});

it('creates and deactivates invites of the tenant only', function (): void {
    isoEnter($this->ownerA, $this->a);

    Livewire::test(ListInvites::class)
        ->callTableAction('create', data: ['label' => 'Group 7B', 'max_uses' => 20, 'telegram_group_id' => $this->groupA1->id])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $invite = OrganizationInvite::query()->where('label', 'Group 7B')->firstOrFail();
    expect($invite->organization_id)->toBe($this->a->id)
        ->and($invite->max_uses)->toBe(20)
        ->and($invite->created_by_user_id)->toBe($this->ownerA->id);

    // A submitted group id of the other center is refused even though the option list never offered it.
    Livewire::test(ListInvites::class)->callTableAction('create', data: ['label' => 'Sneaky', 'telegram_group_id' => $this->groupB->id]);
    expect(OrganizationInvite::query()->where('label', 'Sneaky')->exists())->toBeFalse();

    Livewire::test(ListInvites::class)->callTableAction('deactivate', $invite)->assertNotified();
    expect($invite->fresh()?->is_active)->toBeFalse();
});

it('links a group only when the owner is an administrator of the chat, and unlinks it', function (): void {
    Mail::fake();
    // Created BEFORE entering the cabinet: inside it Filament's tenancy hook would attach every new row to the tenant.
    $free = TelegramGroup::factory()->create(['organization_id' => null, 'title' => 'Free group', 'status' => 'active']);
    isoEnter($this->ownerA, $this->a);

    $this->tg->chatMemberStatus = 'member';
    Livewire::test(ListGroups::class)->callTableAction('link', data: ['chat_id' => (string) $free->chat_id])->assertNotified();
    expect($free->fresh()?->organization_id)->toBeNull();

    $this->tg->chatMemberStatus = 'administrator';
    Livewire::test(ListGroups::class)->callTableAction('link', data: ['chat_id' => (string) $free->chat_id])->assertNotified();
    expect($free->fresh()?->organization_id)->toBe($this->a->id)
        ->and($this->tg->chatMemberLookups)->toContain(['chat_id' => $free->chat_id, 'user_id' => $this->ownerA->telegram_user_id])
        ->and(AuditLog::query()->where('action', 'organization.linked_group')->where('user_id', $this->ownerA->id)->exists())->toBeTrue();

    // The other center's group is not linkable from here even as a chat administrator.
    Livewire::test(ListGroups::class)->callTableAction('link', data: ['chat_id' => (string) $this->groupB->chat_id]);
    expect($this->groupB->fresh()?->organization_id)->toBe($this->b->id);

    Livewire::test(ListGroups::class)->callTableAction('unlink', $free->fresh())->assertNotified();
    expect($free->fresh()?->organization_id)->toBeNull();
});

it('invites, assigns groups to, re-roles and removes staff of the tenant', function (): void {
    Mail::fake();
    isoEnter($this->ownerA, $this->a);

    Livewire::test(ListStaff::class)
        ->callTableAction('invite', data: ['email' => 'teacher2@example.com', 'name' => 'T2', 'role' => 'teacher'])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $staff = OrganizationUser::query()->where('organization_id', $this->a->id)->whereHas('user', fn ($q) => $q->where('email', 'teacher2@example.com'))->firstOrFail();
    Mail::assertSent(OrgStaffInvitationMail::class, fn (OrgStaffInvitationMail $mail): bool => $mail->hasTo('teacher2@example.com') && $mail->withResetLink);

    // Repeating the invitation is refused.
    Livewire::test(ListStaff::class)->callTableAction('invite', data: ['email' => 'Teacher2@example.com', 'role' => 'teacher']);
    expect(OrganizationUser::query()->where('organization_id', $this->a->id)->count())->toBe(3);
    Mail::assertSent(OrgStaffInvitationMail::class, 1);

    Livewire::test(ListStaff::class)->callTableAction('groups', $staff, data: ['groups' => [$this->groupA1->id, $this->groupA2->id]])->assertNotified();
    expect(app(OrganizationStaffService::class)->groupIdsOf($this->a, $staff))->toBe([$this->groupA1->id, $this->groupA2->id]);

    // A group of B cannot be assigned even if submitted.
    Livewire::test(ListStaff::class)->callTableAction('groups', $staff, data: ['groups' => [$this->groupB->id]]);
    expect(app(OrganizationStaffService::class)->groupIdsOf($this->a, $staff))->toBe([$this->groupA1->id, $this->groupA2->id]);

    Livewire::test(ListStaff::class)->callTableAction('role', $staff, data: ['role' => 'owner'])->assertNotified();
    expect($staff->fresh()?->role)->toBe('owner');

    // The owner can now step down because another owner exists; the last owner could not.
    $ownRow = OrganizationUser::query()->where('organization_id', $this->a->id)->where('user_id', $this->ownerA->id)->firstOrFail();
    Livewire::test(ListStaff::class)->callTableAction('role', $ownRow, data: ['role' => 'teacher'])->assertNotified();
    expect($ownRow->fresh()?->role)->toBe('teacher');

    // The acting user is now a teacher: the staff screen is closed to them altogether.
    Livewire::test(ListStaff::class)->assertForbidden();
    expect($staff->fresh())->not->toBeNull();

    // Staff of B is untouchable from A (back as owner).
    OrganizationUser::query()->whereKey($ownRow->id)->update(['role' => 'owner']);
    // Inside the cabinet every resource model carries the tenancy global scope — bypass it to fetch B's row.
    $staffB = OrganizationUser::query()->withoutGlobalScopes()->where('organization_id', $this->b->id)->firstOrFail();
    isoEnter($this->ownerA, $this->a);
    expect(fn () => Livewire::test(ListStaff::class)->callTableAction('remove', $staffB))->toThrow(ActionNotResolvableException::class);
    expect($staffB->fresh())->not->toBeNull();
});

it('lets the owner edit the settings and audits the change; a teacher gets 404', function (): void {
    isoEnter($this->ownerA, $this->a);

    Livewire::test(OrgSettings::class)
        ->fillForm(['name' => 'Center A renamed', 'contact' => ['email' => 'hello@a.example', 'phone' => '', 'telegram' => '@center_a'], 'timezone' => 'Asia/Tashkent', 'locale' => 'uz'])
        ->call('save')
        ->assertHasNoFormErrors();

    $org = $this->a->fresh();
    expect($org?->name)->toBe('Center A renamed')
        ->and($org?->locale)->toBe('uz')
        ->and($org?->settings['contact'] ?? null)->toBe(['email' => 'hello@a.example', 'telegram' => '@center_a'])
        ->and(AuditLog::query()->where('action', 'organization.settings_updated')->where('entity_id', $this->a->id)->exists())->toBeTrue();

    $this->actingAs($this->teacherA, 'org')->get('/org/center-a/profile')->assertNotFound();
});
