<?php

declare(strict_types=1);

use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\RelationManagers\InvitesRelationManager;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->teacher = User::factory()->create(['is_active' => true]);
    $this->teacher->assignRole('teacher');
});

function orgRmCenter(?int $seats = 30): Organization
{
    $org = Organization::factory()->create(['name' => 'Bright Center']);
    if ($seats !== null) {
        Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => $seats]);
    }

    return $org;
}

/** @param class-string<RelationManager> $manager */
function orgRm(string $manager, Organization $org): Testable
{
    return Livewire::test($manager, ['ownerRecord' => $org, 'pageClass' => EditOrganization::class]);
}

// --- access ----------------------------------------------------------------------

it('hides invites and members from teachers and inactive admins', function (): void {
    $org = orgRmCenter();
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('admin');

    foreach ([$this->teacher, $inactive] as $user) {
        $this->actingAs($user)->get('/admin/organizations')->assertForbidden();

        expect(InvitesRelationManager::canViewForRecord($org, EditOrganization::class))->toBeFalse()
            ->and(MembersRelationManager::canViewForRecord($org, EditOrganization::class))->toBeFalse();
    }

    $this->actingAs($this->admin);
    expect(InvitesRelationManager::canViewForRecord($org, EditOrganization::class))->toBeTrue()
        ->and(MembersRelationManager::canViewForRecord($org, EditOrganization::class))->toBeTrue();
});

it('registers both managers on the organization resource', function (): void {
    expect(OrganizationResource::getRelations())->toContain(InvitesRelationManager::class, MembersRelationManager::class);
});

it('does not let a teacher create or deactivate an invite, add or remove a member through Livewire', function (): void {
    $org = orgRmCenter();
    $invite = OrganizationInvite::factory()->create(['organization_id' => $org->id]);
    $learner = Learner::factory()->seatedIn($org)->create();
    $other = Learner::factory()->create();
    $member = OrganizationMember::query()->where('learner_id', $learner->id)->sole();
    $this->actingAs($this->teacher);

    foreach ([
        fn () => orgRm(InvitesRelationManager::class, $org)->callTableAction('createInvite', data: ['label' => 'x']),
        fn () => orgRm(InvitesRelationManager::class, $org)->callTableAction('deactivateInvite', $invite),
        fn () => orgRm(MembersRelationManager::class, $org)->callTableAction('addMember', data: ['learner_id' => $other->id]),
        fn () => orgRm(MembersRelationManager::class, $org)->callTableAction('removeMember', $member),
    ] as $attempt) {
        try {
            $attempt();
        } catch (Throwable) {
            // an unauthorized or hidden action may also fail loudly — what matters is the data below
        }
    }

    expect(OrganizationInvite::query()->count())->toBe(1)
        ->and($invite->refresh()->is_active)->toBeTrue()
        ->and($member->refresh()->status)->toBe('active')
        ->and(OrganizationMember::query()->where('learner_id', $other->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

// --- invites ---------------------------------------------------------------------

it('lists the invites of this organization only, with the copyable link', function (): void {
    config(['telegram.bot_username' => 'lexiflow_bot']);
    $org = orgRmCenter();
    $mine = OrganizationInvite::factory()->create(['organization_id' => $org->id, 'label' => 'Group 7B', 'max_uses' => 20, 'uses_count' => 3]);
    $foreign = OrganizationInvite::factory()->create(['organization_id' => orgRmCenter()->id]);
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnStateSet('link', 'https://t.me/lexiflow_bot?start=org_'.$mine->code, $mine)
        ->assertTableColumnStateSet('uses', '3 / 20', $mine)
        ->assertTableColumnStateSet('code', $mine->code, $mine);
});

it('shows a hint instead of a broken link when the bot username is not configured', function (): void {
    config(['telegram.bot_username' => '']);
    $org = orgRmCenter();
    $invite = OrganizationInvite::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)->assertTableColumnStateSet('link', null, $invite);
});

it('creates an invite through the service for this organization', function (): void {
    config(['telegram.bot_username' => 'lexiflow_bot']);
    $org = orgRmCenter();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)
        ->callTableAction('createInvite', data: [
            'label' => 'Group 7B',
            'telegram_group_id' => $group->id,
            'max_uses' => 25,
            'expires_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Приглашение создано');

    $invite = OrganizationInvite::query()->sole();
    expect($invite->organization_id)->toBe($org->id)
        ->and($invite->label)->toBe('Group 7B')
        ->and($invite->telegram_group_id)->toBe($group->id)
        ->and($invite->max_uses)->toBe(25)
        ->and($invite->uses_count)->toBe(0)
        ->and($invite->is_active)->toBeTrue()
        ->and($invite->expires_at?->isFuture())->toBeTrue()
        ->and($invite->created_by_user_id)->toBe($this->admin->id)
        ->and($invite->code)->toMatch('/^[A-HJ-NP-Z2-9]{10}$/');
});

it('creates an unlimited invite without optional fields', function (): void {
    $org = orgRmCenter();
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)->callTableAction('createInvite', data: [])->assertHasNoTableActionErrors();

    $invite = OrganizationInvite::query()->sole();
    expect($invite->label)->toBeNull()
        ->and($invite->max_uses)->toBeNull()
        ->and($invite->expires_at)->toBeNull();
});

it('does not tie an invite to a group of another organization (the form offers only its own groups)', function (): void {
    $org = orgRmCenter();
    $foreignGroup = TelegramGroup::factory()->create(['organization_id' => orgRmCenter()->id]);
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)
        ->callTableAction('createInvite', data: ['telegram_group_id' => $foreignGroup->id])
        ->assertHasTableActionErrors(['telegram_group_id']);

    expect(OrganizationInvite::query()->count())->toBe(0);
});

it('validates the size of an invite', function (): void {
    $org = orgRmCenter();
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)
        ->callTableAction('createInvite', data: ['max_uses' => 0, 'label' => str_repeat('x', 65)])
        ->assertHasTableActionErrors(['max_uses', 'label']);

    expect(OrganizationInvite::query()->count())->toBe(0);
});

it('deactivates an invite: the code stops working, members stay', function (): void {
    $org = orgRmCenter();
    $invite = OrganizationInvite::factory()->create(['organization_id' => $org->id]);
    $member = Learner::factory()->seatedIn($org)->create();
    $this->actingAs($this->admin);

    orgRm(InvitesRelationManager::class, $org)
        ->assertTableActionVisible('deactivateInvite', $invite)
        ->callTableAction('deactivateInvite', $invite)
        ->assertNotified('Приглашение отключено');

    expect($invite->refresh()->is_active)->toBeFalse()
        ->and(OrganizationMember::query()->where('learner_id', $member->id)->value('status'))->toBe('active');

    orgRm(InvitesRelationManager::class, $org)->assertTableActionHidden('deactivateInvite', $invite);
});

it('cannot delete an invite from the table', function (): void {
    $org = orgRmCenter();
    $invite = OrganizationInvite::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($this->admin);

    expect($this->admin->can('delete', $invite))->toBeFalse();
});

// --- members ---------------------------------------------------------------------

it('lists the active members of this organization with the seat state, ended ones behind the filter', function (): void {
    $org = orgRmCenter(1);
    $seated = Learner::factory()->seatedIn($org)->create(['first_name' => 'Seated']);
    $waiting = Learner::factory()->seatedIn($org, 'waitlisted')->create(['first_name' => 'Waiting']);
    $gone = Learner::factory()->create(['first_name' => 'Gone']);
    $goneMember = OrganizationMember::factory()->create(['organization_id' => $org->id, 'learner_id' => $gone->id, 'status' => 'left', 'seat_state' => 'none']);
    $foreign = Learner::factory()->seatedIn(orgRmCenter())->create();
    $seatedRow = OrganizationMember::query()->where('learner_id', $seated->id)->sole();
    $waitingRow = OrganizationMember::query()->where('learner_id', $waiting->id)->sole();
    $this->actingAs($this->admin);

    orgRm(MembersRelationManager::class, $org)
        ->assertCanSeeTableRecords([$seatedRow, $waitingRow])
        ->assertCanNotSeeTableRecords([$goneMember])
        ->assertTableColumnStateSet('seat_state', 'seated', $seatedRow)
        ->assertTableColumnStateSet('seat_state', 'waitlisted', $waitingRow)
        ->filterTable('status', 'left')
        ->assertCanSeeTableRecords([$goneMember])
        ->assertCanNotSeeTableRecords([$seatedRow, $waitingRow]);

    expect(OrganizationMember::query()->where('learner_id', $foreign->id)->exists())->toBeTrue();
});

it('removes a member: the seat goes to the first waiting one, the action is audited', function (): void {
    $org = orgRmCenter(1);
    $seated = Learner::factory()->seatedIn($org)->create();
    $waiting = Learner::factory()->seatedIn($org, 'waitlisted')->create();
    $member = OrganizationMember::query()->where('learner_id', $seated->id)->sole();
    $this->actingAs($this->admin);

    orgRm(MembersRelationManager::class, $org)
        ->callTableAction('removeMember', $member)
        ->assertNotified('Ученик удалён из центра');

    expect($member->refresh()->status)->toBe('removed')
        ->and($member->seat_state)->toBe('none')
        ->and(OrganizationMember::query()->where('learner_id', $waiting->id)->sole()->seat_state)->toBe('seated');

    $audit = AuditLog::query()->where('action', 'organization.member_removed')->sole();
    expect($audit->user_id)->toBe($this->admin->id)
        ->and($audit->meta['learner_id'])->toBe($seated->id)
        ->and($audit->meta['status'])->toBe('removed');

    // Ended memberships leave the default (active) view and have no "Remove".
    orgRm(MembersRelationManager::class, $org)->assertCanNotSeeTableRecords([$member]);
});

it('adds a learner by an administrator: seated while there are seats, waiting after', function (): void {
    $org = orgRmCenter(1);
    $first = Learner::factory()->create(['first_name' => 'First']);
    $second = Learner::factory()->create(['first_name' => 'Second']);
    $this->actingAs($this->admin);

    orgRm(MembersRelationManager::class, $org)
        ->callTableAction('addMember', data: ['learner_id' => $first->id])
        ->assertNotified('Ученик добавлен и получил место')
        ->callTableAction('addMember', data: ['learner_id' => $second->id])
        ->assertNotified('Ученик добавлен в очередь на место');

    $a = OrganizationMember::query()->where('learner_id', $first->id)->sole();
    $b = OrganizationMember::query()->where('learner_id', $second->id)->sole();
    expect($a->seat_state)->toBe('seated')
        ->and($a->joined_via)->toBe('admin')
        ->and($b->seat_state)->toBe('waitlisted')
        ->and(AuditLog::query()->where('action', 'organization.member_added')->count())->toBe(2);
});

it('does not add a member twice and does not add to a suspended organization', function (): void {
    $org = orgRmCenter();
    $learner = Learner::factory()->seatedIn($org)->create();
    $suspended = Organization::factory()->create(['status' => 'suspended']);
    $stranger = Learner::factory()->create();
    $this->actingAs($this->admin);

    orgRm(MembersRelationManager::class, $org)
        ->callTableAction('addMember', data: ['learner_id' => $learner->id])
        ->assertNotified('Уже состоит в центре');

    orgRm(MembersRelationManager::class, $suspended)
        ->callTableAction('addMember', data: ['learner_id' => $stranger->id])
        ->assertNotified('Ученик не добавлен');

    expect(OrganizationMember::query()->where('organization_id', $org->id)->count())->toBe(1)
        ->and(OrganizationMember::query()->where('organization_id', $suspended->id)->count())->toBe(0);
});

it('finds learners to add by name, username and Telegram id', function (): void {
    $org = orgRmCenter();
    $ali = Learner::factory()->create(['first_name' => 'Alisher', 'last_name' => 'Karimov', 'username' => 'alisher_k', 'telegram_user_id' => 555001]);
    Learner::factory()->create(['first_name' => 'Bobur', 'username' => null, 'telegram_user_id' => 555002]);
    $this->actingAs($this->admin);

    $manager = orgRm(MembersRelationManager::class, $org);
    $search = function (string $term) use ($manager): array {
        $method = new ReflectionMethod($manager->instance(), 'searchLearners');

        return $method->invoke($manager->instance(), $term);
    };

    expect(array_keys($search('alish')))->toBe([$ali->id])
        ->and(array_keys($search('@alisher_k')))->toBe([$ali->id])
        ->and(array_keys($search('555001')))->toBe([$ali->id])
        ->and($search('nobody'))->toBe([])
        ->and($search('alish')[$ali->id])->toBe('Alisher Karimov (@alisher_k) · 555001');
});
