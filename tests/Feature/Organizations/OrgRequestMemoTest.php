<?php

declare(strict_types=1);

use App\Domain\Administration\Services\OrganizationGroupLinker;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrganizationStaffService;
use App\Domain\Organizations\Services\OrgRequestMemo;
use App\Domain\Organizations\Services\OrgTenantScope;
use App\Filament\Org\Resources\MemberResource\Pages\ListMembers;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The per-request memory of the `/org` access checks (OrgRequestMemo, docs/20 F4): the role of a user in a center
 * and the groups they may see are read once per request, never answer for another user or center, follow a change
 * made in the same request, and do not outlive the request or the queued job.
 */

uses(RefreshDatabase::class);

function memoStaff(Organization $organization, OrganizationRole $role, ?User $user = null): User
{
    $user ??= User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** Puts the test into the cabinet of $tenant as $user, as the panel middleware would. */
function memoEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

/** @param list<string> $queries */
function roleQueries(array $queries): int
{
    return count(QueryLog::matching($queries, '/^select ["`]role["`] from ["`]organization_users["`]/'));
}

/** @param list<string> $queries */
function groupIdQueries(array $queries): int
{
    return count(QueryLog::matching($queries, '/^select ["`]telegram_groups["`]\.["`]id["`] from ["`]telegram_groups["`]/'));
}

it('keeps the role and the groups of every (user, center) pair apart and reads each once', function (): void {
    $a = Organization::factory()->create(['slug' => 'memo-a']);
    $b = Organization::factory()->create(['slug' => 'memo-b']);
    $one = memoStaff($a, OrganizationRole::Owner);
    memoStaff($b, OrganizationRole::Teacher, $one);
    $two = memoStaff($b, OrganizationRole::Owner);
    $nobody = User::factory()->create(['is_active' => true]);
    [$a1, $a2] = TelegramGroup::factory()->count(2)->create(['organization_id' => $a->id]);
    [$b1, $b2] = TelegramGroup::factory()->count(2)->create(['organization_id' => $b->id]);
    $one->telegramGroups()->attach($b1->id, ['is_primary' => true]);
    $scope = app(OrgTenantScope::class);

    $first = QueryLog::during(function () use ($scope, $a, $b, $one, $two, $nobody, $a1, $a2, $b1, $b2): void {
        expect($scope->role($one, $a))->toBe(OrganizationRole::Owner)
            ->and($scope->role($one, $b))->toBe(OrganizationRole::Teacher)
            ->and($scope->role($two, $a))->toBeNull()
            ->and($scope->role($two, $b))->toBe(OrganizationRole::Owner)
            ->and($scope->role($nobody, $a))->toBeNull()
            ->and($scope->teacherGroupIds($one, $a))->toBe([$a1->id, $a2->id])
            ->and($scope->teacherGroupIds($one, $b))->toBe([$b1->id])
            ->and($scope->teacherGroupIds($two, $b))->toBe([$b1->id, $b2->id])
            ->and($scope->teacherGroupIds($two, $a))->toBe([])
            ->and($scope->teacherGroupIds($nobody, $a))->toBe([])
            ->and($scope->canSeeGroup($one, $b, $b2))->toBeFalse()
            ->and($scope->canSeeGroup($one, $b, $b1))->toBeTrue()
            ->and($scope->canSeeGroup($one, $a, $b1))->toBeFalse();
    });

    // Five pairs asked for the role, three of them (the staff ones) for their groups.
    expect(roleQueries($first))->toBe(5)
        ->and(groupIdQueries($first))->toBe(3);

    $again = QueryLog::during(function () use ($scope, $a, $b, $one, $two, $nobody, $b1): void {
        expect($scope->role($one, $a))->toBe(OrganizationRole::Owner)
            ->and($scope->isOwner($two, $b))->toBeTrue()
            ->and($scope->isStaff($nobody, $a))->toBeFalse()
            ->and($scope->teacherGroupIds($one, $b))->toBe([$b1->id])
            ->and($scope->canSeeGroup($two, $b, $b1))->toBeTrue();
    });

    expect($again)->toBe([]);
});

it('serves the tenant check of the panel and the policies from the same lookup', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-shared']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    memoEnter($owner, $organization);

    $queries = QueryLog::during(function () use ($owner, $organization): void {
        expect($owner->canAccessTenant($organization))->toBeTrue()
            ->and($owner->organizationRole($organization))->toBe(OrganizationRole::Owner)
            ->and($owner->isOrganizationOwner($organization->id))->toBeTrue()
            ->and(app(OrgTenantScope::class)->isOwner($owner, $organization))->toBeTrue()
            ->and($owner->can('viewAny', OrganizationMember::class))->toBeTrue()
            ->and($owner->can('create', OrganizationMember::class))->toBeTrue();
    });

    // One lookup of the role; the other statements are spatie's permission cache behind `can()`.
    expect(roleQueries($queries))->toBe(1)
        ->and(QueryLog::matching($queries, '/["`]organization_users["`]/'))->toHaveCount(1);
});

it('reads the role once per request of a list page, whatever the number of rows and actions', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-page']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    OrganizationMember::factory()->count(12)->create(['organization_id' => $organization->id]);
    memoEnter($owner, $organization);

    foreach ([1, 2] as $request) {
        // Every request starts without the memory, as in PHP-FPM, Octane and the queue worker.
        app()->forgetScopedInstances();

        $queries = QueryLog::during(fn () => Livewire::test(ListMembers::class)->assertCountTableRecords(12));

        expect(roleQueries($queries))->toBe(1, "request {$request}");
    }
});

it('follows a role changed in the same request through the staff service', function (): void {
    Mail::fake();
    $organization = Organization::factory()->create(['slug' => 'memo-staff']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    $teacher = memoStaff($organization, OrganizationRole::Teacher);
    $newcomer = User::factory()->create(['is_active' => true, 'email' => 'newcomer@example.test']);
    $scope = app(OrgTenantScope::class);
    $staff = app(OrganizationStaffService::class);

    // Warm the memory for everyone involved.
    expect($scope->role($teacher, $organization))->toBe(OrganizationRole::Teacher)
        ->and($scope->role($newcomer, $organization))->toBeNull()
        ->and($scope->isOwner($owner, $organization))->toBeTrue();

    $teacherRow = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $teacher->id)->firstOrFail();
    $staff->changeRole($organization, $teacherRow, OrganizationRole::Owner, $owner);
    $after = QueryLog::during(fn () => expect($scope->role($teacher, $organization))->toBe(OrganizationRole::Owner));
    expect(roleQueries($after))->toBe(1);

    $staff->invite($organization, 'newcomer@example.test', null, OrganizationRole::Teacher, $owner);
    expect($scope->role($newcomer, $organization))->toBe(OrganizationRole::Teacher)
        ->and($newcomer->canAccessTenant($organization))->toBeTrue();

    $ownerRow = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $owner->id)->firstOrFail();
    $staff->remove($organization, $ownerRow, $teacher);
    expect($scope->role($owner, $organization))->toBeNull()
        ->and($owner->canAccessTenant($organization))->toBeFalse()
        ->and($scope->isOwner($teacher, $organization))->toBeTrue();
});

it('follows a staff row written outside the service through the model', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-model']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    $scope = app(OrgTenantScope::class);

    expect($scope->isOwner($owner, $organization))->toBeTrue();

    $row = OrganizationUser::query()->where('user_id', $owner->id)->firstOrFail();
    $row->update(['role' => OrganizationRole::Teacher->value]);
    expect($scope->role($owner, $organization))->toBe(OrganizationRole::Teacher);

    $row->delete();
    expect($scope->role($owner, $organization))->toBeNull();

    OrganizationUser::factory()->teacher()->create(['organization_id' => $organization->id, 'user_id' => $owner->id]);
    expect($scope->role($owner, $organization))->toBe(OrganizationRole::Teacher);
});

it('follows the groups of a teacher and the links of the center changed in the same request', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-groups']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    $teacher = memoStaff($organization, OrganizationRole::Teacher);
    [$g1, $g2] = TelegramGroup::factory()->count(2)->create(['organization_id' => $organization->id]);
    $assignment = Assignment::factory()->create(['organization_id' => $organization->id, 'telegram_group_id' => $g2->id]);
    $scope = app(OrgTenantScope::class);
    $teacherRow = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $teacher->id)->firstOrFail();

    expect($scope->teacherGroupIds($teacher, $organization))->toBe([])
        ->and($scope->canSeeGroup($teacher, $organization, $g1))->toBeFalse()
        ->and($scope->canSeeAssignment($owner, $organization, $assignment))->toBeTrue();

    app(OrganizationStaffService::class)->syncGroups($organization, $teacherRow, [$g1->id], $owner);
    expect($scope->teacherGroupIds($teacher, $organization))->toBe([$g1->id])
        ->and($scope->canSeeGroup($teacher, $organization, $g1))->toBeTrue()
        ->and($scope->canSeeAssignment($teacher, $organization, $assignment))->toBeFalse();

    app(OrganizationStaffService::class)->syncGroups($organization, $teacherRow, [$g1->id, $g2->id], $owner);
    expect($scope->canSeeAssignment($teacher, $organization, $assignment))->toBeTrue();

    // The group leaves the center: its assignment is nobody's here any more, for the owner too.
    app(OrganizationGroupLinker::class)->unlink($organization, $g2, $owner);
    expect($scope->canSeeAssignment($owner, $organization, $assignment->fresh()))->toBeFalse()
        ->and($scope->canSeeAssignment($teacher, $organization, $assignment->fresh()))->toBeFalse()
        ->and($scope->teacherGroupIds($owner, $organization))->toBe([$g1->id]);

    // Removal takes the teacher's groups with it (a query-builder delete) — nothing stays visible.
    app(OrganizationStaffService::class)->remove($organization, $teacherRow, $owner);
    expect($scope->teacherGroupIds($teacher, $organization))->toBe([])
        ->and($scope->canSeeGroup($teacher, $organization, $g1))->toBeFalse();
});

it('does not carry the memory from one HTTP request into the next', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-requests']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    $session = ['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $owner->id];

    $this->actingAs($owner, 'org')->withSession($session)->get('/org/memo-requests/staff')->assertOk();

    // A query-builder write fires no model event; only the end of the request drops the memory.
    OrganizationUser::query()->where('user_id', $owner->id)->update(['role' => OrganizationRole::Teacher->value]);

    $this->actingAs($owner, 'org')->withSession($session)->get('/org/memo-requests/staff')->assertForbidden();
});

it('is forgotten by the queue worker before each job', function (): void {
    $organization = Organization::factory()->create(['slug' => 'memo-worker']);
    $owner = memoStaff($organization, OrganizationRole::Owner);
    $memo = app(OrgRequestMemo::class);

    expect(app(OrgTenantScope::class)->isOwner($owner, $organization))->toBeTrue()
        ->and($memo->has("role:{$owner->id}:{$organization->id}"))->toBeTrue();

    // The reset callback Laravel 13 gives the worker (QueueServiceProvider::registerWorker), run by Worker::daemon()
    // before every job: scoped instances are forgotten, so the next resolution is a fresh memory.
    $worker = app('queue.worker');
    $reset = (new ReflectionProperty(Worker::class, 'resetScope'))->getValue($worker);
    expect($reset)->toBeCallable();
    $reset();

    $fresh = app(OrgRequestMemo::class);
    expect($fresh)->not->toBe($memo)
        ->and($fresh->has("role:{$owner->id}:{$organization->id}"))->toBeFalse()
        ->and(roleQueries(QueryLog::during(fn () => app(OrgTenantScope::class)->isOwner($owner, $organization))))->toBe(1);
});
