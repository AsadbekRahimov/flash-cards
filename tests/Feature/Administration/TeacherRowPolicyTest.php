<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Administration\Services\PanelRequestMemo;
use App\Filament\Resources\ExamSessionResource;
use App\Filament\Resources\ExamSessionResource\Pages\ListExamSessions;
use App\Filament\Resources\LearnerResource;
use App\Filament\Resources\LearnerResource\Pages\ListLearners;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The row policies of a teacher in /admin (LearnerPolicy::view, ExamSessionPolicy::view; docs/20 A11, docs/07 §5) answer
 * from the teacher's groups, read once per request (PanelRequestMemo), and from the memberships the list loaded - and
 * say exactly what the queries of PanelDataScope say: teacher A sees the learners and the exams of A's groups, never B's,
 * whether the record comes from a list (relation loaded), from a bare model, or by a direct URL.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->groupA = TelegramGroup::factory()->create(['title' => 'Group A']);
    $this->groupB = TelegramGroup::factory()->create(['title' => 'Group B']);
    $this->teacherA = trpTeacher($this->groupA);
    $this->teacherB = trpTeacher($this->groupB);

    $this->learnerA = Learner::factory()->memberOf($this->groupA)->create(['first_name' => 'Anna']);
    $this->learnerB = Learner::factory()->memberOf($this->groupB)->create(['first_name' => 'Bob']);
    $this->leaverA = Learner::factory()->memberOf($this->groupA, 'left')->create(['first_name' => 'Gone']);
    $this->examA = ExamSession::factory()->create(['telegram_group_id' => $this->groupA->id, 'status' => 'closed']);
    $this->examB = ExamSession::factory()->create(['telegram_group_id' => $this->groupB->id, 'status' => 'closed']);
});

function trpTeacher(TelegramGroup $group): User
{
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);

    return $teacher;
}

/** @param list<string> $queries */
function trpGroupLookups(array $queries): int
{
    return count(QueryLog::matching($queries, '/from ["`]teacher_groups["`] where ["`]user_id["`] = \?/'));
}

it('lists to a teacher only the learners and the exams of their own groups, and lets every listed row be opened', function (): void {
    $this->actingAs($this->teacherA);

    Livewire::test(ListLearners::class)
        ->assertCanSeeTableRecords([$this->learnerA])
        ->assertCanNotSeeTableRecords([$this->learnerB, $this->leaverA])
        ->assertTableActionVisible('view', $this->learnerA);
    Livewire::test(ListExamSessions::class)
        ->assertCanSeeTableRecords([$this->examA])
        ->assertCanNotSeeTableRecords([$this->examB])
        ->assertTableActionVisible('view', $this->examA);

    $this->actingAs($this->teacherB);

    Livewire::test(ListLearners::class)
        ->assertCanSeeTableRecords([$this->learnerB])
        ->assertCanNotSeeTableRecords([$this->learnerA, $this->leaverA]);
    Livewire::test(ListExamSessions::class)
        ->assertCanSeeTableRecords([$this->examB])
        ->assertCanNotSeeTableRecords([$this->examA]);
});

it('answers 404 to a teacher who opens a learner or an exam of another teacher by URL', function (): void {
    $this->actingAs($this->teacherA);

    $this->get(LearnerResource::getUrl('view', ['record' => $this->learnerA]))->assertOk();
    $this->get(LearnerResource::getUrl('view', ['record' => $this->learnerB]))->assertNotFound();
    $this->get(LearnerResource::getUrl('view', ['record' => $this->leaverA]))->assertNotFound();
    $this->get(ExamSessionResource::getUrl('view', ['record' => $this->examA]))->assertOk();
    $this->get(ExamSessionResource::getUrl('view', ['record' => $this->examB]))->assertNotFound();
});

it('decides the same for a bare record and for a record whose memberships the list loaded', function (): void {
    $scope = app(PanelDataScope::class);
    $bare = fn (Learner $learner): Learner => Learner::query()->findOrFail($learner->id);
    $loaded = fn (Learner $learner): Learner => Learner::query()->with('activeGroupMemberships')->findOrFail($learner->id);

    foreach ([$bare, $loaded] as $load) {
        expect($scope->canViewLearner($this->teacherA, $load($this->learnerA)))->toBeTrue()
            ->and($scope->canViewLearner($this->teacherA, $load($this->learnerB)))->toBeFalse()
            ->and($scope->canViewLearner($this->teacherA, $load($this->leaverA)))->toBeFalse()
            ->and($scope->canViewLearner($this->teacherB, $load($this->learnerB)))->toBeTrue()
            ->and($scope->canViewLearner($this->teacherB, $load($this->learnerA)))->toBeFalse();
    }

    expect($scope->canViewExam($this->teacherA, $this->examA))->toBeTrue()
        ->and($scope->canViewExam($this->teacherA, $this->examB))->toBeFalse()
        ->and($scope->canViewExam($this->teacherB, $this->examB))->toBeTrue()
        ->and($scope->canViewExam($this->teacherB, $this->examA))->toBeFalse()
        // A personal mock has no group: no teacher's.
        ->and($scope->canViewExam($this->teacherA, ExamSession::factory()->personalMock()->create()))->toBeFalse();

    // The policies say the same through the Gate, for the signed-in teacher and for another one.
    $this->actingAs($this->teacherA);
    expect(Gate::allows('view', $this->learnerA))->toBeTrue()
        ->and(Gate::allows('view', $this->learnerB))->toBeFalse()
        ->and(Gate::allows('view', $this->examA))->toBeTrue()
        ->and(Gate::allows('view', $this->examB))->toBeFalse()
        ->and(Gate::forUser($this->teacherB)->allows('view', $this->learnerA))->toBeFalse()
        ->and(Gate::forUser($this->teacherB)->allows('view', $this->examB))->toBeTrue();
});

it('reads the groups of a teacher once per request and keeps them apart from another teacher', function (): void {
    $scope = app(PanelDataScope::class);
    $rows = Learner::query()->with('activeGroupMemberships')->get()->keyBy('id');

    $first = QueryLog::during(function () use ($scope, $rows): void {
        foreach ($rows as $learner) {
            $scope->canViewLearner($this->teacherA, $learner);
            $scope->canViewLearner($this->teacherB, $learner);
        }
        $scope->canViewExam($this->teacherA, $this->examA);
        $scope->canViewExam($this->teacherB, $this->examA);
    });

    // Two teachers, one lookup of the groups each - whatever the number of rows and checks - and nothing about the rows
    // (the other statements are the roles of the two users, read once per user by spatie).
    expect(trpGroupLookups($first))->toBe(2)
        ->and(QueryLog::matching($first, '/["`]group_members["`]|["`]learners["`]|["`]exam_sessions["`]/'))->toBe([])
        ->and($scope->teacherGroupIds($this->teacherA))->toBe([$this->groupA->id])
        ->and($scope->teacherGroupIds($this->teacherB))->toBe([$this->groupB->id]);

    // A row the list did not load the memberships of is the one query the policy still runs.
    $learner = Learner::query()->findOrFail($this->learnerA->id);
    $bare = QueryLog::during(fn () => expect($scope->canViewLearner($this->teacherA, $learner))->toBeTrue());
    expect($bare)->toHaveCount(1)
        ->and($bare[0])->toContain('group_members');
});

it('does not carry the groups of a teacher from one request into the next', function (): void {
    $this->actingAs($this->teacherA);
    $this->get(LearnerResource::getUrl('view', ['record' => $this->learnerB]))->assertNotFound();

    // The teacher is given group B by an administrator (a query-builder write, as the admin form does it).
    $this->teacherA->telegramGroups()->attach($this->groupB->id, ['is_primary' => false]);

    $this->get(LearnerResource::getUrl('view', ['record' => $this->learnerB]))->assertOk();
    $this->get(ExamSessionResource::getUrl('view', ['record' => $this->examB]))->assertOk();
});

it('is a scoped memory: forgotten with the scoped instances, flushed on demand', function (): void {
    $scope = app(PanelDataScope::class);
    $memo = app(PanelRequestMemo::class);

    expect($scope->teacherGroupIds($this->teacherA))->toBe([$this->groupA->id])
        ->and($memo->has("teacher_groups:{$this->teacherA->id}"))->toBeTrue();

    $this->teacherA->telegramGroups()->attach($this->groupB->id, ['is_primary' => false]);
    expect($scope->teacherGroupIds($this->teacherA))->toBe([$this->groupA->id]);

    $memo->flush();
    expect($scope->teacherGroupIds($this->teacherA))->toBe([$this->groupA->id, $this->groupB->id]);

    app()->forgetScopedInstances();
    expect(app(PanelRequestMemo::class))->not->toBe($memo)
        ->and(app(PanelRequestMemo::class)->has("teacher_groups:{$this->teacherA->id}"))->toBeFalse();
});
