<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Resources\GroupResource\Pages\ViewGroup;
use App\Models\GroupMember;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The page of a group in the cabinet of a center (/org, docs/20 A10): the number of its active members is the figure
 * `withCount` of the page query already carries, not a count of its own.
 */

uses(RefreshDatabase::class);

it('shows the active members of a group from the page query, without counting them again', function (): void {
    $organization = Organization::factory()->create(['slug' => 'group-view-center']);
    $owner = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    $this->actingAs($owner, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $owner->id);
    Filament::setTenant($organization, isQuiet: true);

    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'title' => 'Evening Group']);
    GroupMember::factory()->count(3)->create(['telegram_group_id' => $group->id]);
    GroupMember::factory()->left()->create(['telegram_group_id' => $group->id]);

    Livewire::test(ViewGroup::class, ['record' => $group->getRouteKey()]);

    $statements = QueryLog::during(function () use ($group): void {
        Livewire::test(ViewGroup::class, ['record' => $group->getRouteKey()])
            ->assertSee('Evening Group')
            ->assertSeeInOrder(['Учеников', '3']);
    });

    // The three active members are counted by a subselect of the page query, not by a statement of their own.
    expect(QueryLog::matching($statements, '/^select count\(\*\) as ["`]aggregate["`] from ["`]group_members["`]/'))->toBe([]);
});
