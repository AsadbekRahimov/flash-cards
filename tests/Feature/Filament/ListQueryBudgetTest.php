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
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

/*
 * Query budget of the list pages (docs/20 §1.13): the number of statements of a list page does not grow with the number
 * of rows (10 -> 50) and stays within a ceiling. `/admin/learners` is the example of the helper; every list page and
 * relation manager of the `/org` cabinet is covered for the owner and for a teacher (docs/20 F4): their row policies
 * answer from the per-request memory of OrgTenantScope and from relations the list query loaded, never per row.
 */

uses(RefreshDatabase::class);

/** A staff account with 2FA confirmed, so the panel opens for it. */
function budgetStaff(Organization $organization, OrganizationRole $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role->value]);

    return $user;
}

/** Puts the test into the cabinet of $tenant as $user, as the panel middleware would. */
function budgetEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

/**
 * A center with a course, one group the teacher runs, an owner and a teacher.
 *
 * @return array{organization: Organization, owner: User, teacher: User, group: TelegramGroup, lesson: Lesson}
 */
function budgetCenter(): array
{
    $organization = Organization::factory()->create(['slug' => 'budget-center']);
    $owner = budgetStaff($organization, OrganizationRole::Owner);
    $teacher = budgetStaff($organization, OrganizationRole::Teacher);
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'course_id' => $course->id, 'status' => 'active']);
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);

    return ['organization' => $organization, 'owner' => $owner, 'teacher' => $teacher, 'group' => $group, 'lesson' => $lesson];
}

/** @param list<string> $queries */
function roleLookups(array $queries): int
{
    return count(QueryLog::matching($queries, '/^select ["`]role["`] from ["`]organization_users["`]/'));
}

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

    // 6 statements for 10 rows and for 50: two renders (the mount and the change of the page size) of the page, the groups of its
    // learners and the options of the group filter; no count of the rows (the table pages Simple), the plan subqueries ride in the
    // page query.
    $queries = FilamentQueryBudget::assert(ListLearners::class, $fill, ceiling: 8);

    expect(count($queries[FilamentQueryBudget::SMALL]))->toBe(count($queries[FilamentQueryBudget::LARGE]));
});

it('lists the members of a center within budget, reading the role of the user once per page', function (string $who): void {
    $c = budgetCenter();
    budgetEnter($c[$who], $c['organization']);

    // Learners seated in the center and active in the teacher's group: what the columns and the row policy read. Every
    // fourth learner is soft-deleted afterwards: the membership row stays, the row's learner loads as null.
    $fill = function (int $rows) use ($c): void {
        $have = OrganizationMember::query()->where('organization_id', $c['organization']->id)->count();
        Learner::factory()
            ->count($rows - $have)
            ->memberOf($c['group'])
            ->seatedIn($c['organization'])
            ->afterCreating(function (Learner $learner): void {
                if ($learner->id % 4 === 0) {
                    $learner->delete();
                }
            })
            ->create();
    };

    // Owner: the page, its count, the learners; teacher: plus the learners' active group memberships for the policy, and no
    // query for a row whose learner is gone.
    $queries = FilamentQueryBudget::assert(ListMembers::class, $fill, ceiling: $who === 'owner' ? 10 : 14);

    // The measured window holds two requests of the page (the first render and the per-page update), and the tenant
    // check, every policy and every action of a request share one lookup of the role (docs/20 F4; OrgRequestMemoTest).
    expect(roleLookups($queries[FilamentQueryBudget::LARGE]))->toBe(2)
        ->and(count($queries[FilamentQueryBudget::SMALL]))->toBe(count($queries[FilamentQueryBudget::LARGE]));
})->with(['owner', 'teacher']);

it('lists the groups of a center within budget', function (string $who): void {
    $c = budgetCenter();
    budgetEnter($c[$who], $c['organization']);

    // Groups the teacher runs, each with teachers (a column) and active learners (the count column).
    $fill = function (int $rows) use ($c): void {
        $have = TelegramGroup::query()->where('organization_id', $c['organization']->id)->count();
        TelegramGroup::factory()
            ->count($rows - $have)
            ->create(['organization_id' => $c['organization']->id, 'status' => 'active'])
            ->each(function (TelegramGroup $group) use ($c): void {
                $c['teacher']->telegramGroups()->attach($group->id, ['is_primary' => false]);
                Learner::factory()->count(2)->memberOf($group)->create();
            });
    };

    $queries = FilamentQueryBudget::assert(ListGroups::class, $fill, ceiling: 12);

    expect(roleLookups($queries[FilamentQueryBudget::LARGE]))->toBe(2);
})->with(['owner', 'teacher']);

it('lists the invites of a center within budget', function (): void {
    $c = budgetCenter();
    budgetEnter($c['owner'], $c['organization']);

    $fill = function (int $rows) use ($c): void {
        $have = OrganizationInvite::query()->where('organization_id', $c['organization']->id)->count();
        OrganizationInvite::factory()->count($rows - $have)->create(['organization_id' => $c['organization']->id, 'telegram_group_id' => $c['group']->id]);
    };

    $queries = FilamentQueryBudget::assert(ListInvites::class, $fill, ceiling: 10);

    expect(roleLookups($queries[FilamentQueryBudget::LARGE]))->toBe(2);
});

it('lists the staff of a center within budget, counting their groups in the page query', function (): void {
    $c = budgetCenter();
    budgetEnter($c['owner'], $c['organization']);

    // Teachers with a group of the center each (the "groups" column is an aggregate of the page query).
    $fill = function (int $rows) use ($c): void {
        $have = OrganizationUser::query()->where('organization_id', $c['organization']->id)->count();
        OrganizationUser::factory()
            ->teacher()
            ->count($rows - $have)
            ->create(['organization_id' => $c['organization']->id])
            ->each(fn (OrganizationUser $staff) => DB::table('teacher_groups')->insert([
                'user_id' => $staff->user_id,
                'telegram_group_id' => $c['group']->id,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
    };

    $queries = FilamentQueryBudget::assert(ListStaff::class, $fill, ceiling: 10);

    expect(roleLookups($queries[FilamentQueryBudget::LARGE]))->toBe(2)
        ->and(QueryLog::matching($queries[FilamentQueryBudget::LARGE], '/from ["`]teacher_groups["`] where ["`]user_id["`] = \?/'))->toBe([]);
});

it('lists the assignments of a center within budget', function (string $who): void {
    $c = budgetCenter();
    budgetEnter($c[$who], $c['organization']);

    // Assignments of the teacher's group, each with a result row of an active learner (the progress column counts them).
    $fill = function (int $rows) use ($c): void {
        $have = Assignment::query()->where('organization_id', $c['organization']->id)->count();
        // Every assignment in its own module and lesson, with the default title (a copy of the lesson's): `displayTitle()` reads the
        // module's course for each of them, so a lazy-loaded course shows up as growth with the number of rows.
        Assignment::factory()
            ->count($rows - $have)
            ->state(function () use ($c): array {
                $stage = Stage::factory()->create(['course_id' => $c['group']->course_id]);
                $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);

                return ['organization_id' => $c['organization']->id, 'telegram_group_id' => $c['group']->id, 'lesson_id' => $lesson->id, 'title' => $lesson->title];
            })
            ->create()
            ->each(function (Assignment $assignment) use ($c): void {
                $learner = Learner::factory()->memberOf($c['group'])->create();
                AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $learner->id]);
            });
    };

    $queries = FilamentQueryBudget::assert(ListAssignments::class, $fill, ceiling: 20);

    expect(roleLookups($queries[FilamentQueryBudget::LARGE]))->toBe(2)
        ->and(QueryLog::matching($queries[FilamentQueryBudget::LARGE], '/^select exists\(select \* from ["`]telegram_groups["`]/'))->toBe([]);
})->with(['owner', 'teacher']);

it('lists the results of an assignment within budget', function (string $who): void {
    $c = budgetCenter();
    budgetEnter($c[$who], $c['organization']);
    $assignment = Assignment::factory()->create(['organization_id' => $c['organization']->id, 'telegram_group_id' => $c['group']->id, 'lesson_id' => $c['lesson']->id]);

    // Result rows of learners active in the group (rows of learners who left are not listed), a third of them done.
    $fill = function (int $rows) use ($c, $assignment): void {
        $have = AssignmentResult::query()->where('assignment_id', $assignment->id)->count();

        for ($i = $have; $i < $rows; $i++) {
            $learner = Learner::factory()->memberOf($c['group'])->create();
            AssignmentResult::factory()
                ->when($i % 3 === 0, fn ($factory) => $factory->completed())
                ->create(['assignment_id' => $assignment->id, 'learner_id' => $learner->id]);
        }
    };

    FilamentQueryBudget::assert(ResultsRelationManager::class, $fill, ceiling: 16, parameters: ['ownerRecord' => $assignment, 'pageClass' => ViewAssignment::class]);
})->with(['owner', 'teacher']);

it('reports the statements that run once per row when a page has an N+1', function (): void {
    $small = ['select * from "a" where "id" = ?', 'select * from "b" where "a_id" = ?'];
    $large = ['select * from "a" where "id" = ?', ...array_fill(0, 40, 'select * from "b" where "a_id" = ?')];

    expect(FilamentQueryBudget::grown($small, $large))->toBe(['1x -> 40x: select * from "b" where "a_id" = ?'])
        ->and(FilamentQueryBudget::grown($small, $small))->toBe([]);
});

it('fails the budget of a page whose statements grow with the rows', function (): void {
    $c = budgetCenter();
    budgetEnter($c['owner'], $c['organization']);

    $fill = fn (int $rows) => OrganizationMember::factory()
        ->count($rows - OrganizationMember::query()->where('organization_id', $c['organization']->id)->count())
        ->create(['organization_id' => $c['organization']->id]);

    // A ceiling below the real count fails and the message lists the statements.
    expect(fn () => FilamentQueryBudget::assert(ListMembers::class, $fill, ceiling: 3))
        ->toThrow(ExpectationFailedException::class, 'against a budget of 3');
});
