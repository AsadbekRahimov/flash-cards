<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationMemberInvitation;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Invitations of learners (audit 2026-11-10 M2, review finding B1) in `/admin`: they are not memberships — the members table
 * lists present and past memberships only, the seats widget counts seats only — and the Telegram verification of a user in the
 * user list.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->org = Organization::factory()->create(['name' => 'Bright Center']);
    Subscription::factory()->forOrganization($this->org)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
});

it('lists memberships only: an invitation is no row of the members table and no status of its filter', function (): void {
    $active = OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'active', 'seat_state' => 'seated']);
    $left = OrganizationMember::factory()->left()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Gone'])->id]);
    OrganizationMemberInvitation::factory()->create(['organization_id' => $this->org->id, 'invited_handle' => '@offered_one', 'learner_id' => Learner::factory()->create(['first_name' => 'Offered'])->id]);

    $manager = fn () => Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->org, 'pageClass' => EditOrganization::class]);

    $manager()->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$left])->assertDontSee('Offered')->assertDontSee('@offered_one');
    $manager()->filterTable('status', 'left')->assertCanSeeTableRecords([$left])->assertCanNotSeeTableRecords([$active])->assertSee('Вышел');

    expect(__('admin.organizations.members.status'))->toBe(['active' => 'Активен', 'left' => 'Вышел', 'removed' => 'Удалён']);
});

it('does not count an invited learner as a seat or an active member of the center', function (): void {
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'active', 'seat_state' => 'seated']);
    OrganizationMemberInvitation::factory()->count(3)->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id]);
    // A forged combination: whatever `seat_state` says, only an active member holds a seat.
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'left', 'seat_state' => 'seated']);

    $row = collect(app(AdminDashboardStats::class)->organizationSeats(1, 10))->firstWhere('id', $this->org->id);

    expect($row['members_count'])->toBe(1)
        ->and($row['waitlisted_count'])->toBe(0);
});

it('shows in the user list whether the Telegram account is verified', function (): void {
    $verified = User::factory()->create(['telegram_user_id' => 11, 'telegram_verified_at' => now()]);
    $typed = User::factory()->create(['telegram_user_id' => 22, 'telegram_verified_at' => null]);
    $none = User::factory()->create(['telegram_user_id' => null, 'telegram_verified_at' => null]);

    Livewire::test(ListUsers::class)
        ->assertTableColumnStateSet('telegram_verified_at', true, $verified)
        ->assertTableColumnStateSet('telegram_verified_at', false, $typed)
        ->assertTableColumnStateSet('telegram_verified_at', false, $none);
});

it('tells the admin that a typed Telegram id is not verified', function (): void {
    expect(__('admin.users.fields.telegram_user_id_help'))
        ->toContain('Введённый вручную ID считается неподтверждённым: пользователь подтверждает его кнопкой «Подключить Telegram» в кабинете');
});
