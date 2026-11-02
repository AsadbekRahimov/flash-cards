<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Resources\AssignmentResource\Pages\ListAssignments;
use App\Filament\Org\Resources\AssignmentResource\Pages\ViewAssignment;
use App\Filament\Org\Resources\AssignmentResource\RelationManagers\ResultsRelationManager;
use App\Filament\Org\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Org\Resources\InviteResource\Pages\ListInvites;
use App\Filament\Org\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Org\Resources\StaffResource\Pages\ListStaff;
use App\Filament\Resources\Support\AdminTable;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

/*
 * The page sizes of the tables of the /org cabinet (docs/20 §1.6): 10/25/50/100 from the select, and ENFORCED - a forged
 * `tableRecordsPerPage` ("all", a million) gets the default page, never every row of the center.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'sizes-center']);
    $this->owner = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $this->organization->id, 'user_id' => $this->owner->id, 'role' => OrganizationRole::Owner->value]);

    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    $this->actingAs($this->owner, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $this->owner->id);
    Filament::setTenant($this->organization, isQuiet: true);
});

dataset('org lists', [
    'members' => [ListMembers::class, fn (Organization $organization) => OrganizationMember::factory()->count(12)->create(['organization_id' => $organization->id])],
    'groups' => [ListGroups::class, fn (Organization $organization) => TelegramGroup::factory()->count(12)->create(['organization_id' => $organization->id])],
    'invites' => [ListInvites::class, fn (Organization $organization) => OrganizationInvite::factory()->count(12)->create(['organization_id' => $organization->id])],
    'staff' => [ListStaff::class, fn (Organization $organization) => OrganizationUser::factory()->teacher()->count(12)->create(['organization_id' => $organization->id])],
    'assignments' => [ListAssignments::class, fn (Organization $organization) => Assignment::factory()->count(12)->create(['organization_id' => $organization->id, 'telegram_group_id' => TelegramGroup::factory()->create(['organization_id' => $organization->id])->id])],
]);

it('offers the sizes 10 to 100 and never "all" on every list of the cabinet', function (string $page, Closure $fill): void {
    $fill($this->organization);
    $table = Livewire::test($page)->instance()->getTable();

    expect($table->getPaginationPageOptions())->toBe(AdminTable::PAGE_SIZES)
        ->and($table->getPaginationPageOptions())->not->toContain('all')
        ->and(max($table->getPaginationPageOptions()))->toBeLessThanOrEqual(100)
        ->and($table->enforcesPaginationPageOptions())->toBeTrue()
        ->and($table->getDefaultPaginationPageOption())->toBe(10);
})->with('org lists');

it('answers a forged page size ("all", a million rows) with the default page of ten rows', function (string $page, Closure $fill): void {
    $fill($this->organization);
    $component = Livewire::test($page);

    foreach (['all', 1_000_000, 'DROP', 7] as $forged) {
        $component->set('tableRecordsPerPage', $forged);

        expect($component->instance()->getTableRecordsPerPage())->toBe(10)
            ->and($component->instance()->getTableRecords())->toHaveCount(10);
    }

    // A size from the select is honoured (the staff list has the owner's row on top of the twelve).
    $component->set('tableRecordsPerPage', 25);
    expect($component->instance()->getTableRecordsPerPage())->toBe(25)
        ->and($component->instance()->getTableRecords()->count())->toBeGreaterThanOrEqual(12)->toBeLessThanOrEqual(13);
})->with('org lists');

it('bounds the page of the results of an assignment the same way', function (): void {
    $group = TelegramGroup::factory()->create(['organization_id' => $this->organization->id]);
    $assignment = Assignment::factory()->create(['organization_id' => $this->organization->id, 'telegram_group_id' => $group->id]);
    for ($i = 0; $i < 12; $i++) {
        AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => Learner::factory()->memberOf($group)->create()->id]);
    }

    $component = Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $assignment, 'pageClass' => ViewAssignment::class]);
    $table = $component->instance()->getTable();

    expect($table->getPaginationPageOptions())->toBe(AdminTable::PAGE_SIZES)
        ->and($table->enforcesPaginationPageOptions())->toBeTrue();

    $component->set('tableRecordsPerPage', 'all');
    expect($component->instance()->getTableRecordsPerPage())->toBe(10)
        ->and($component->instance()->getTableRecords())->toHaveCount(10);
});
