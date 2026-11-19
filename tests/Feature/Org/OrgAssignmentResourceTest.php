<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Services\OrgHardWordsReport;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Filament\Org\Pages\Assignments;
use App\Filament\Org\Resources\AssignmentResource;
use App\Filament\Org\Resources\AssignmentResource\Pages\CreateAssignment;
use App\Filament\Org\Resources\AssignmentResource\Pages\ListAssignments;
use App\Filament\Org\Resources\AssignmentResource\Pages\ViewAssignment;
use App\Filament\Org\Resources\AssignmentResource\RelationManagers\ResultsRelationManager;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\GroupMember;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\FakeTelegramClient;

/*
 * The /org assignments screen (docs/13 §6.3, DoD 2B.4): owner — all groups of the organization,
 * teacher — only their own, another organization — 404; creation goes through AssignmentService
 * (the lesson must be one of the group's course).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tg = new FakeTelegramClient;
    $this->app->instance(TelegramClient::class, $this->tg);

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);
    Subscription::factory()->forOrganization($this->a)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);

    $this->ownerA = asgStaff($this->a, OrganizationRole::Owner);
    $this->ownerB = asgStaff($this->b, OrganizationRole::Owner);
    $this->teacherA = asgStaff($this->a, OrganizationRole::Teacher, twoFactor: false);

    $this->course = Course::factory()->published()->create();
    $this->stage = Stage::factory()->create(['course_id' => $this->course->id, 'number' => 1]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $this->stage->id, 'number' => 1, 'title' => 'Greetings']);
    $this->words = Word::factory()->count(2)->for($this->lesson)->create();
    $this->otherLesson = Lesson::factory()->create(); // another course

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $this->course->id, 'title' => 'Mine', 'status' => 'active']);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $this->course->id, 'title' => 'Not mine', 'status' => 'active']);
    $this->gB = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'course_id' => $this->course->id, 'title' => 'Other center', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->as1 = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->g1->id, 'lesson_id' => $this->lesson->id]);
    $this->as2 = Assignment::factory()->create(['organization_id' => $this->a->id, 'telegram_group_id' => $this->g2->id, 'lesson_id' => $this->lesson->id]);
    $this->asB = Assignment::factory()->create(['organization_id' => $this->b->id, 'telegram_group_id' => $this->gB->id, 'lesson_id' => $this->lesson->id]);
});

function asgStaff(Organization $organization, OrganizationRole $role, bool $twoFactor = true): User
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

function asgEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

it('lists all assignments of the organization for the owner and nothing of another center', function (): void {
    asgEnter($this->ownerA, $this->a);

    Livewire::test(ListAssignments::class)
        ->assertCanSeeTableRecords([$this->as1, $this->as2])
        ->assertCanNotSeeTableRecords([$this->asB]);
});

it('lists only the assignments of their own groups for a teacher', function (): void {
    asgEnter($this->teacherA, $this->a);

    Livewire::test(ListAssignments::class)
        ->assertCanSeeTableRecords([$this->as1])
        ->assertCanNotSeeTableRecords([$this->as2, $this->asB]);
});

it('answers 404 to a record of another organization or of a group the teacher does not run', function (): void {
    $this->actingAs($this->ownerA, 'org')->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $this->ownerA->id]);
    $this->get("/org/center-a/assignments/{$this->as1->id}")->assertOk();
    $this->get("/org/center-a/assignments/{$this->as2->id}")->assertOk();
    $this->get("/org/center-a/assignments/{$this->asB->id}")->assertNotFound();
    $this->get("/org/center-b/assignments/{$this->asB->id}")->assertNotFound();

    $this->actingAs($this->teacherA, 'org')->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $this->teacherA->id]);
    $this->get("/org/center-a/assignments/{$this->as1->id}")->assertOk();
    $this->get("/org/center-a/assignments/{$this->as2->id}")->assertNotFound();
});

it('shows the group, lesson, due date, status and X of Y done in the list', function (): void {
    $done = Learner::factory()->memberOf($this->g1)->create();
    $pending = Learner::factory()->memberOf($this->g1)->create();
    $left = Learner::factory()->memberOf($this->g1, 'left')->create();
    AssignmentResult::factory()->completed()->create(['assignment_id' => $this->as1->id, 'learner_id' => $done->id]);
    AssignmentResult::factory()->create(['assignment_id' => $this->as1->id, 'learner_id' => $pending->id]);
    AssignmentResult::factory()->completed()->create(['assignment_id' => $this->as1->id, 'learner_id' => $left->id]);
    asgEnter($this->ownerA, $this->a);

    Livewire::test(ListAssignments::class)
        ->assertTableColumnStateSet('group.title', 'Mine', $this->as1)
        ->assertTableColumnStateSet('title', 'Greetings', $this->as1)
        ->assertTableColumnStateSet('lesson', '1.1', $this->as1)
        ->assertTableColumnStateSet('status', 'active', $this->as1)
        ->assertTableColumnStateSet('progress', 'не начали 1 · в процессе 0 · выполнили 1 из 2', $this->as1);
});

/**
 * Snapshot rows of as1 in every state: done, done late, in progress, not started, a learner who joined the group after the assignment
 * was given (counted), and one who left (a completed row, not counted). Rows fresh, so opening the report recounts nothing.
 *
 * @return array<string, Learner>
 */
function asgStates(Assignment $assignment, TelegramGroup $group): array
{
    $learners = [];
    $rows = [
        'done' => ['progress_pct' => 100, 'completed_at' => now()->subHour()],
        'late' => ['progress_pct' => 100, 'completed_at' => $assignment->due_at->copy()->addHour()],
        'in_progress' => ['progress_pct' => 50, 'completed_at' => null],
        'not_started' => ['progress_pct' => 0, 'completed_at' => null],
    ];

    foreach ($rows as $state => $row) {
        $learners[$state] = Learner::factory()->memberOf($group)->create();
        AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $learners[$state]->id, ...$row]);
    }

    $learners['joined_later'] = Learner::factory()->create();
    GroupMember::factory()->create(['telegram_group_id' => $group->id, 'learner_id' => $learners['joined_later']->id, 'status' => 'active', 'joined_at' => now()->addDay()]);
    AssignmentResult::factory()->create(['assignment_id' => $assignment->id, 'learner_id' => $learners['joined_later']->id]);

    $learners['left'] = Learner::factory()->memberOf($group, 'left')->create();
    AssignmentResult::factory()->completed()->create(['assignment_id' => $assignment->id, 'learner_id' => $learners['left']->id]);

    return $learners;
}

it('counts not started, in progress and done (late too) of the learners in the group now', function (): void {
    asgStates($this->as1, $this->g1);

    $assignment = Assignment::query()->whereKey($this->as1->id)->withProgressCounts()->sole();

    // 5 learners in the group (the one who joined later too, not the one who left): 2 not started, 1 in progress, 2 done.
    expect([$assignment->total_count, $assignment->started_count, $assignment->done_count])->toBe([5, 3, 2])
        ->and(AssignmentResource::progressSummary($assignment))->toBe(['not_started' => 2, 'in_progress' => 1, 'done' => 2, 'total' => 5]);

    asgEnter($this->ownerA, $this->a);
    Livewire::test(ListAssignments::class)
        ->assertTableColumnStateSet('progress', 'не начали 2 · в процессе 1 · выполнили 2 из 5', $this->as1)
        ->assertTableColumnStateSet('progress', 'не начали 0 · в процессе 0 · выполнили 0 из 0', $this->as2);
});

it('counts a completed row as started even when its percent rounds down to 0', function (): void {
    $learner = Learner::factory()->memberOf($this->g1)->create();
    AssignmentResult::factory()->create(['assignment_id' => $this->as1->id, 'learner_id' => $learner->id, 'progress_pct' => 0, 'completed_at' => now()]);

    $assignment = Assignment::query()->whereKey($this->as1->id)->withProgressCounts()->sole();

    expect(AssignmentResource::progressSummary($assignment))->toBe(['not_started' => 0, 'in_progress' => 0, 'done' => 1, 'total' => 1]);
});

it('shows the three numbers and who is counted next to the due date on the view page', function (): void {
    asgStates($this->as1, $this->g1);
    asgEnter($this->teacherA, $this->a);

    Livewire::test(ViewAssignment::class, ['record' => $this->as1->id])
        ->assertSeeInOrder(['Срок', 'Ученики по заданию', 'Не начали', '2', 'В процессе', '1', 'Выполнили', '2 из 5'])
        ->assertSee('вступившие после выдачи задания — тоже, вышедшие — нет')
        // No hard words of the lesson in the group: no block at all.
        ->assertDontSee('Что повторить');
});

it('counts a learner who joined after a recount once the report is opened', function (): void {
    $done = Learner::factory()->memberOf($this->g1)->create();
    foreach ($this->words as $word) {
        WordRepetition::factory()->create(['learner_id' => $done->id, 'word_id' => $word->id, 'repetitions' => 2]);
    }
    // A stale snapshot from before the newcomer joined.
    AssignmentResult::factory()->create(['assignment_id' => $this->as1->id, 'learner_id' => $done->id, 'computed_at' => now()->subHour()]);
    $newcomer = Learner::factory()->memberOf($this->g1)->create();
    $left = Learner::factory()->memberOf($this->g1, 'left')->create();
    AssignmentResult::factory()->completed()->create(['assignment_id' => $this->as1->id, 'learner_id' => $left->id, 'computed_at' => now()->subHour()]);
    asgEnter($this->ownerA, $this->a);

    // The page shows the counts of the snapshot it has just recounted, not those of the stale one.
    Livewire::test(ViewAssignment::class, ['record' => $this->as1->id])
        ->assertSeeInOrder(['Не начали', '1', 'В процессе', '0', 'Выполнили', '1 из 2']);

    expect(AssignmentResult::query()->where('assignment_id', $this->as1->id)->where('learner_id', $newcomer->id)->exists())->toBeTrue();
});

it('shows up to three hard words of the lesson that at least two learners of the group keep failing', function (): void {
    config(['learning.leech.threshold' => 4]);
    $words = Word::factory()->count(3)->for($this->lesson)->create();
    $foreignWord = Word::factory()->create(['word' => 'elsewhere']);
    [$l1, $l2, $l3] = Learner::factory()->count(3)->memberOf($this->g1)->seatedIn($this->a)->create()->all();
    $notMine = Learner::factory()->count(2)->memberOf($this->g2)->seatedIn($this->a)->create()->all();
    $lapses = fn (Learner $learner, Word $word, int $n) => WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'lapses' => $n]);

    // words[0]: 3 learners of g1; words[1]: 2 (one below the threshold does not count); words[2]: 1 only - not shown;
    // a word of another lesson: 3 learners - not this lesson's; the learners of g2 fail words[2] - not this group.
    foreach ([$l1, $l2, $l3] as $learner) {
        $lapses($learner, $words[0], 5);
        $lapses($learner, $foreignWord, 9);
    }
    $lapses($l1, $words[1], 4);
    $lapses($l2, $words[1], 4);
    $lapses($l3, $words[1], 3);
    $lapses($l1, $words[2], 9);
    foreach ($notMine as $learner) {
        $lapses($learner, $words[2], 9);
    }

    asgEnter($this->teacherA, $this->a);

    Livewire::test(ViewAssignment::class, ['record' => $this->as1->id])
        ->assertSee('Что повторить')
        ->assertSeeInOrder([$words[0]->word.' — '.$words[0]->translation.' (ошибаются: 3)', $words[1]->word.' — '.$words[1]->translation.' (ошибаются: 2)'])
        ->assertDontSee($words[2]->word.' — ')
        ->assertDontSee('elsewhere');

    // The owner sees the g2 assignment's material: the two learners of g2 on words[2].
    asgEnter($this->ownerA, $this->a);
    Livewire::test(ViewAssignment::class, ['record' => $this->as2->id])
        ->assertSee($words[2]->word.' — '.$words[2]->translation.' (ошибаются: 2)')
        ->assertDontSee($words[0]->word.' — ');
});

it('does not give a teacher the summary or the hard words of another teacher\'s group', function (): void {
    config(['learning.leech.threshold' => 4]);
    $word = Word::factory()->for($this->lesson)->create();
    foreach (Learner::factory()->count(2)->memberOf($this->g2)->seatedIn($this->a)->create() as $learner) {
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'lapses' => 9]);
        AssignmentResult::factory()->completed()->create(['assignment_id' => $this->as2->id, 'learner_id' => $learner->id]);
    }

    $this->actingAs($this->teacherA, 'org')->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $this->teacherA->id]);
    $this->get("/org/center-a/assignments/{$this->as2->id}")->assertNotFound();

    asgEnter($this->teacherA, $this->a);
    Livewire::test(ViewAssignment::class, ['record' => $this->as2->id])->assertNotFound();

    // The service itself answers nothing for a group outside the teacher's scope, even when asked directly.
    expect(app(OrgHardWordsReport::class)->toRepeat($this->teacherA, $this->a, $this->g2, $this->lesson->id))->toBe([])
        ->and(app(OrgHardWordsReport::class)->toRepeat($this->ownerA, $this->a, $this->g2, $this->lesson->id))->toHaveCount(1)
        ->and(app(OrgHardWordsReport::class)->toRepeat($this->ownerB, $this->b, $this->g2, $this->lesson->id))->toBe([]);
});

it('creates an assignment for a group of the owner and announces it', function (): void {
    asgEnter($this->ownerA, $this->a);

    Livewire::test(CreateAssignment::class)
        ->fillForm([
            'telegram_group_id' => $this->g2->id,
            'lesson_id' => $this->lesson->id,
            'due_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
            'completion_threshold' => 80,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = Assignment::query()->where('telegram_group_id', $this->g2->id)->latest('id')->firstOrFail();
    expect($created->id)->not->toBe($this->as2->id)
        ->and($created->organization_id)->toBe($this->a->id)
        ->and($created->title)->toBe('Greetings')
        ->and($created->completion_threshold)->toBe(80)
        ->and($created->created_by_user_id)->toBe($this->ownerA->id);
    // The queue is sync in tests: the group got its announcement.
    expect(collect($this->tg->sent)->pluck('chat_id')->all())->toContain($this->g2->chat_id);
});

it('offers a teacher only their own groups and lets them create for those', function (): void {
    asgEnter($this->teacherA, $this->a);

    expect(AssignmentResource::groupOptions())->toBe([$this->g1->id => 'Mine'])
        ->and(AssignmentResource::lessonOptions($this->g1->id))->toHaveKey($this->lesson->id)
        ->and(AssignmentResource::lessonOptions($this->g2->id))->toBe([])
        ->and(AssignmentResource::lessonOptions($this->gB->id))->toBe([]);

    Livewire::test(CreateAssignment::class)
        ->fillForm(['telegram_group_id' => $this->g1->id, 'lesson_id' => $this->lesson->id, 'due_at' => now()->addDay()->format('Y-m-d H:i:s'), 'completion_threshold' => 100])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Assignment::query()->where('telegram_group_id', $this->g1->id)->count())->toBe(2);
});

it('refuses a group the teacher does not run, a group of another center and a lesson of another course', function (): void {
    asgEnter($this->teacherA, $this->a);
    $before = Assignment::query()->count();

    foreach ([[$this->g2->id, $this->lesson->id], [$this->gB->id, $this->lesson->id], [$this->g1->id, $this->otherLesson->id]] as [$group, $lesson]) {
        // The option lists are a convenience only: the handler re-checks the submitted ids.
        try {
            Livewire::test(CreateAssignment::class)
                ->set('data.telegram_group_id', $group)
                ->set('data.lesson_id', $lesson)
                ->set('data.due_at', now()->addDay()->format('Y-m-d H:i:s'))
                ->set('data.completion_threshold', 100)
                ->call('create');
        } catch (Throwable) {
            // A halted create may surface as an exception in some Livewire versions; the DB check below decides.
        }
    }

    expect(Assignment::query()->count())->toBe($before);
});

it('closes an assignment from the list and the view page, keeping the snapshot', function (): void {
    $learner = Learner::factory()->memberOf($this->g1)->create();
    foreach ($this->words as $word) {
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $word->id, 'repetitions' => 2]);
    }
    asgEnter($this->ownerA, $this->a);

    Livewire::test(ListAssignments::class)->callTableAction('close', $this->as1);

    expect($this->as1->fresh()->status)->toBe(Assignment::STATUS_CLOSED)
        ->and(AssignmentResult::query()->where('assignment_id', $this->as1->id)->sole()->completed_at)->not->toBeNull();

    Livewire::test(ViewAssignment::class, ['record' => $this->as2->id])->callAction('close');
    expect($this->as2->fresh()->status)->toBe(Assignment::STATUS_CLOSED);
});

it('does not let a teacher close an assignment of another group', function (): void {
    asgEnter($this->teacherA, $this->a);

    expect(fn () => Livewire::test(ListAssignments::class)->callTableAction('close', $this->as2))
        ->toThrow(ActionNotResolvableException::class);
    expect($this->as2->fresh()->status)->toBe(Assignment::STATUS_ACTIVE);
});

it('shows per-learner progress only as an aggregate, for learners still in the group', function (): void {
    $anna = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Anna']);
    $bob = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Bob']);
    $gone = Learner::factory()->memberOf($this->g1, 'left')->create(['first_name' => 'Gone']);
    $ra = AssignmentResult::factory()->completed()->create(['assignment_id' => $this->as1->id, 'learner_id' => $anna->id]);
    $rb = AssignmentResult::factory()->create(['assignment_id' => $this->as1->id, 'learner_id' => $bob->id, 'progress_pct' => 25]);
    $rg = AssignmentResult::factory()->create(['assignment_id' => $this->as1->id, 'learner_id' => $gone->id]);
    $other = AssignmentResult::factory()->create(['assignment_id' => $this->as2->id, 'learner_id' => $bob->id]);
    asgEnter($this->ownerA, $this->a);

    Livewire::test(ResultsRelationManager::class, ['ownerRecord' => $this->as1, 'pageClass' => ViewAssignment::class])
        ->assertCanSeeTableRecords([$ra, $rb])
        ->assertCanNotSeeTableRecords([$rg, $other])
        ->assertTableColumnStateSet('result', 'done', $ra)
        ->assertTableColumnStateSet('result', 'in_progress', $rb);

    expect(Schema::hasColumn('assignment_results', 'telegram_user_id'))->toBeFalse();
});

it('refreshes a stale snapshot when the report is opened', function (): void {
    $learner = Learner::factory()->memberOf($this->g1)->create();
    asgEnter($this->ownerA, $this->a);

    Livewire::test(ViewAssignment::class, ['record' => $this->as1->id]);

    expect(AssignmentResult::query()->where('assignment_id', $this->as1->id)->where('learner_id', $learner->id)->exists())->toBeTrue();
});

it('has a nav entry and no stub left for assignments', function (): void {
    expect(class_exists(Assignments::class))->toBeFalse()
        ->and(AssignmentResource::getNavigationLabel())->toBe('Задания');
});
