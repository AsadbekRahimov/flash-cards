<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Filament\Resources\OrganizationResource\Pages\EditOrganization;
use App\Filament\Resources\OrganizationResource\RelationManagers\MembersRelationManager;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
 * Offers to learners (`invited`, `declined`) in `/admin` (audit 2026-11-10 M2): they have captions and a filter option, and are
 * not seats; and the Telegram verification of a user in the user list.
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

it('captions the offered and declined members and filters by them', function (): void {
    $invited = OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Offered'])->id, 'status' => 'invited', 'seat_state' => 'none']);
    $declined = OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Refused'])->id, 'status' => 'declined', 'seat_state' => 'none']);
    $active = OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'active', 'seat_state' => 'seated']);

    $manager = fn () => Livewire::test(MembersRelationManager::class, ['ownerRecord' => $this->org, 'pageClass' => EditOrganization::class]);

    $manager()->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$invited, $declined]);

    $manager()->filterTable('status', 'invited')
        ->assertCanSeeTableRecords([$invited])->assertCanNotSeeTableRecords([$active, $declined])
        ->assertSee('Ожидает согласия');

    $manager()->filterTable('status', 'declined')
        ->assertCanSeeTableRecords([$declined])->assertCanNotSeeTableRecords([$active, $invited])
        ->assertSee('Отклонил');
});

it('does not count an offered or declined learner as a seat or an active member of the center', function (): void {
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'active', 'seat_state' => 'seated']);
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'invited', 'seat_state' => 'none']);
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'declined', 'seat_state' => 'none']);
    // A forged combination: whatever `seat_state` says, only an active member holds a seat.
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'invited', 'seat_state' => 'seated']);
    OrganizationMember::factory()->create(['organization_id' => $this->org->id, 'learner_id' => Learner::factory()->create()->id, 'status' => 'declined', 'seat_state' => 'waitlisted']);

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
