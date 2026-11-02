<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Resources\MemberResource\Pages\ListMembers;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\FilamentQueryBudget;

/*
 * Query budget of the list pages (docs/20 §1.13), the examples of the helper: the number of statements of a list page does not
 * grow with the number of rows (10 -> 50) and stays within a ceiling. The other list pages follow in docs/20 F4.
 */

uses(RefreshDatabase::class);

it('lists learners with a number of statements that does not depend on the number of rows', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    $group = TelegramGroup::factory()->create(['status' => 'active']);

    // Rows with what the columns read: a group, a plan (every third learner is Pro), onboarding.
    $fill = function (int $rows) use ($group): void {
        Learner::factory()
            ->count($rows - Learner::query()->count())
            ->memberOf($group)
            ->onboarded()
            ->afterCreating(function (Learner $learner): void {
                if ($learner->id % 3 === 0) {
                    Subscription::factory()->create(['subscriber_type' => 'learner', 'subscriber_id' => $learner->id]);
                }
            })
            ->create();
    };

    // 8 statements for 10 rows and for 50 (the page, its count, the groups, the plan subqueries ride in the page query).
    $queries = FilamentQueryBudget::assert(ListLearners::class, $fill, ceiling: 10);

    expect(count($queries[FilamentQueryBudget::SMALL]))->toBe(count($queries[FilamentQueryBudget::LARGE]));
});

it('measures the members of a center, which still read the role of the user once per row and action', function (): void {
    $organization = Organization::factory()->create(['slug' => 'budget-center']);
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

    $fill = function (int $rows) use ($organization): void {
        OrganizationMember::factory()
            ->count($rows - OrganizationMember::query()->where('organization_id', $organization->id)->count())
            ->create(['organization_id' => $organization->id]);
    };

    // A recorded N+1 (docs/20 A11, task F4): the row actions read `organization_users.role` for every row, about 14
    // statements per row (156 for 10 rows, 716 for 50). The budget pins today's cost so it cannot get worse; F4 fixes the
    // read and turns this into the proper budget: `knownPerRow` 0 and a ceiling of a few statements.
    $queries = FilamentQueryBudget::assert(ListMembers::class, $fill, ceiling: 750, knownPerRow: 15);

    $grown = FilamentQueryBudget::grown($queries[FilamentQueryBudget::SMALL], $queries[FilamentQueryBudget::LARGE]);
    expect($grown)->not->toBeEmpty()
        ->and($grown[0])->toContain('"organization_users"');
});

it('reports the statements that run once per row when a page has an N+1', function (): void {
    $small = ['select * from "a" where "id" = ?', 'select * from "b" where "a_id" = ?'];
    $large = ['select * from "a" where "id" = ?', ...array_fill(0, 40, 'select * from "b" where "a_id" = ?')];

    expect(FilamentQueryBudget::grown($small, $large))->toBe(['1x -> 40x: select * from "b" where "a_id" = ?'])
        ->and(FilamentQueryBudget::grown($small, $small))->toBe([]);
});

it('fails the budget of a page whose statements grow with the rows', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $organization = Organization::factory()->create(['slug' => 'growing-center']);
    $owner = User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    $this->actingAs($owner, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $owner->id);
    Filament::setTenant($organization, isQuiet: true);

    $fill = fn (int $rows) => OrganizationMember::factory()
        ->count($rows - OrganizationMember::query()->where('organization_id', $organization->id)->count())
        ->create(['organization_id' => $organization->id]);

    // With the default of 0 the same page is over budget, and the message names the statement that repeats.
    expect(fn () => FilamentQueryBudget::assert(ListMembers::class, $fill, ceiling: 750))
        ->toThrow(ExpectationFailedException::class, 'organization_users');
});
