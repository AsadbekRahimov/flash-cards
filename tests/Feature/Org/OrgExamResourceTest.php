<?php

declare(strict_types=1);

use App\Domain\Learning\Services\ExamReportService;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Resources\ExamResource\Pages\ListExams;
use App\Filament\Org\Resources\ExamResource\Pages\ViewExam;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * The "Exams" page of `/org`: the owner sees the exams of every group of the center, a teacher those of their own groups, another
 * center's (and a group the teacher does not run) are invisible and 404; the page of an exam gives the results by learner, those who
 * did not take part and the hardest words; the results export to CSV with formulas defused.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');

    $this->a = Organization::factory()->create(['name' => 'Center A', 'slug' => 'center-a']);
    $this->b = Organization::factory()->create(['name' => 'Center B', 'slug' => 'center-b']);

    $this->ownerA = examStaff($this->a, OrganizationRole::Owner);
    $this->ownerB = examStaff($this->b, OrganizationRole::Owner);
    $this->teacherA = examStaff($this->a, OrganizationRole::Teacher);
    $this->idleTeacher = examStaff($this->a, OrganizationRole::Teacher);

    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 2]);
    $this->lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 3]);

    $this->g1 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $course->id, 'title' => 'Mine', 'status' => 'active']);
    $this->g2 = TelegramGroup::factory()->create(['organization_id' => $this->a->id, 'course_id' => $course->id, 'title' => 'Not mine', 'status' => 'active']);
    $this->gB = TelegramGroup::factory()->create(['organization_id' => $this->b->id, 'course_id' => $course->id, 'title' => 'Other center', 'status' => 'active']);
    $this->teacherA->telegramGroups()->attach($this->g1->id, ['is_primary' => true]);

    $this->e1 = examSession($this->g1, $this->lesson);
    $this->e2 = examSession($this->g2, $this->lesson);
    $this->eB = examSession($this->gB, $this->lesson);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function examStaff(Organization $organization, OrganizationRole $role): User
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

/** @param array<string, mixed> $attributes */
function examSession(TelegramGroup $group, Lesson $lesson, array $attributes = []): ExamSession
{
    return ExamSession::factory()->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'status' => 'closed',
        'started_at' => now()->subHours(2),
        'ends_at' => now()->subHour(),
        'closed_at' => now()->subHour(),
        ...$attributes,
    ]);
}

function examEnter(User $user, Organization $tenant): void
{
    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($user, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $user->id);
    Filament::setTenant($tenant, isQuiet: true);
}

function examVisit(User $user, string $path): TestResponse
{
    return test()->actingAs($user, 'org')
        ->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $user->id])
        ->get($path);
}

/** Answers of a learner in an exam: one flag per word (true = right); the leaderboard is rebuilt. */
function examAnswers(ExamSession $exam, Learner $learner, array $words, array $flags): void
{
    foreach ($words as $i => $word) {
        ExamAnswer::factory()->create([
            'exam_session_id' => $exam->id,
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'is_correct' => $flags[$i],
            'score' => $flags[$i] ? 10 : 0,
            'time_spent_ms' => 1000,
        ]);
    }
    app(LeaderboardBuilder::class)->build($exam);
}

// ---------------------------------------------------------------------- who sees what

it('lists the exams of every group of the center for the owner and nothing of another center', function (): void {
    examEnter($this->ownerA, $this->a);

    Livewire::test(ListExams::class)
        ->assertCanSeeTableRecords([$this->e1, $this->e2])
        ->assertCanNotSeeTableRecords([$this->eB])
        ->assertSee('Mine')
        ->assertSee('Модуль 2 · Урок 3');
});

it('lists only the exams of their own groups for a teacher, and nothing for a teacher without a group', function (): void {
    examEnter($this->teacherA, $this->a);
    Livewire::test(ListExams::class)
        ->assertCanSeeTableRecords([$this->e1])
        ->assertCanNotSeeTableRecords([$this->e2, $this->eB]);

    examEnter($this->idleTeacher, $this->a);
    Livewire::test(ListExams::class)->assertCanNotSeeTableRecords([$this->e1, $this->e2, $this->eB]);
});

it('does not list a personal mock', function (): void {
    $mock = ExamSession::factory()->personalMock()->create();
    examEnter($this->ownerA, $this->a);

    Livewire::test(ListExams::class)->assertCanNotSeeTableRecords([$mock]);
});

it('answers 404 to an exam of another center or of a group the teacher does not run, 200 to the own', function (): void {
    examVisit($this->ownerA, "/org/center-a/exams/{$this->eB->id}")->assertNotFound();
    examVisit($this->ownerA, "/org/center-a/exams/{$this->e2->id}")->assertOk();
    examVisit($this->teacherA, "/org/center-a/exams/{$this->e2->id}")->assertNotFound();
    examVisit($this->teacherA, "/org/center-a/exams/{$this->e1->id}")->assertOk();
    examVisit($this->idleTeacher, "/org/center-a/exams/{$this->e1->id}")->assertNotFound();
    // the other center's owner is not staff of this center: the panel's own check
    examVisit($this->ownerB, '/org/center-a/exams')->assertForbidden();
    examVisit($this->ownerB, "/org/center-a/exams/{$this->e1->id}")->assertForbidden();
});

it('keeps an exam of a group moved to another center out of the list', function (): void {
    $this->g2->forceFill(['organization_id' => $this->b->id])->save();
    examEnter($this->ownerA, $this->a);

    Livewire::test(ListExams::class)->assertCanNotSeeTableRecords([$this->e2])->assertCanSeeTableRecords([$this->e1]);
});

// ---------------------------------------------------------------------- list

it('shows participants, the average result and the duration of an exam', function (): void {
    $words = Word::factory()->count(2)->for($this->lesson)->create()->all();
    examAnswers($this->e1, Learner::factory()->memberOf($this->g1)->create(), $words, [true, true]);
    examAnswers($this->e1, Learner::factory()->memberOf($this->g1)->create(), $words, [true, false]);
    examEnter($this->teacherA, $this->a);

    $component = Livewire::test(ListExams::class)->assertSee('75%')->assertSee('60 мин');
    $row = $component->instance()->getTableRecords()->first();
    expect((int) $row->participants_count)->toBe(2);
});

it('filters the list by group and by period, newest first', function (): void {
    $old = examSession($this->g1, $this->lesson, ['started_at' => now()->subDays(20), 'ends_at' => now()->subDays(20)->addHour(), 'closed_at' => now()->subDays(20)->addHour()]);
    examEnter($this->ownerA, $this->a);

    Livewire::test(ListExams::class)
        ->assertCanSeeTableRecords([$this->e1, $this->e2, $old])
        ->filterTable('telegram_group_id', $this->g2->id)
        ->assertCanSeeTableRecords([$this->e2])
        ->assertCanNotSeeTableRecords([$this->e1, $old])
        ->removeTableFilter('telegram_group_id')
        ->filterTable('period', ['from' => '2026-11-01', 'until' => '2026-11-06'])
        ->assertCanSeeTableRecords([$this->e1, $this->e2])
        ->assertCanNotSeeTableRecords([$old]);

    // newest first: the exam of 20 days ago comes last
    expect(Livewire::test(ListExams::class)->instance()->getTableRecords()->last()?->id)->toBe($old->id);
});

// ---------------------------------------------------------------------- the exam

it('computes results, absent learners and the hardest words from the answers', function (): void {
    [$w1, $w2, $w3] = Word::factory()->count(3)->for($this->lesson)->sequence(['word' => 'apple'], ['word' => 'bridge'], ['word' => 'cat'])->create()->all();
    $words = [$w1, $w2, $w3];
    $anna = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Anna', 'last_name' => 'Karimova']);
    $bob = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Bob', 'last_name' => null]);
    $carl = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Carl', 'last_name' => null]);
    Learner::factory()->memberOf($this->g1, 'left')->create(['first_name' => 'Gone']);
    Learner::factory()->create(['first_name' => 'Outsider']);
    examAnswers($this->e1, $anna, $words, [true, true, true]);
    examAnswers($this->e1, $bob, $words, [true, false, false]);

    $service = app(ExamReportService::class);

    $results = $service->results($this->e1);
    expect($results)->toHaveCount(2)
        ->and($results[0])->toMatchArray(['name' => 'Anna Karimova', 'correct' => 3, 'total' => 3, 'percent' => 100.0, 'rank' => 1, 'score' => 30])
        ->and($results[1])->toMatchArray(['name' => 'Bob', 'correct' => 1, 'total' => 3, 'percent' => 33.3, 'rank' => 2]);

    expect($service->absent($this->e1))->toBe(['Carl']);

    $hardest = $service->words($this->e1);
    expect(array_column($hardest, 'word'))->toBe(['bridge', 'cat', 'apple'])
        ->and($hardest[0])->toMatchArray(['correct' => 1, 'total' => 2, 'percent' => 50.0])
        ->and($hardest[2])->toMatchArray(['correct' => 2, 'total' => 2, 'percent' => 100.0]);

    // another exam of the same lesson does not leak into these numbers
    examAnswers($this->e2, $carl, $words, [false, false, false]);
    expect($service->words($this->e1)[0]['total'])->toBe(2);
});

it('does not name a deleted account but keeps its result', function (): void {
    $words = Word::factory()->count(1)->for($this->lesson)->create()->all();
    $gone = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Zafar']);
    examAnswers($this->e1, $gone, $words, [true]);
    $gone->delete();

    $results = app(ExamReportService::class)->results($this->e1);

    expect($results)->toHaveCount(1)->and($results[0]['name'])->toBe('—')->and($results[0]['correct'])->toBe(1);
});

it('shows the exam page with the results, the absent and the hardest words to a teacher of the group', function (): void {
    $words = Word::factory()->count(2)->for($this->lesson)->sequence(['word' => 'apple'], ['word' => 'bridge'])->create()->all();
    $anna = Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Anna']);
    Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Carl']);
    examAnswers($this->e1, $anna, $words, [true, false]);
    examEnter($this->teacherA, $this->a);

    Livewire::test(ViewExam::class, ['record' => $this->e1->id])
        ->assertSee('Anna')
        ->assertSee('Carl')
        ->assertSee('bridge')
        ->assertSee('apple')
        ->assertSee('50%');
});

// ---------------------------------------------------------------------- CSV

it('exports the results to CSV with a defused formula in a name', function (): void {
    $words = Word::factory()->count(2)->for($this->lesson)->create()->all();
    examAnswers($this->e1, Learner::factory()->memberOf($this->g1)->create(['first_name' => '=HYPERLINK("x")', 'last_name' => null]), $words, [true, true]);
    examAnswers($this->e1, Learner::factory()->memberOf($this->g1)->create(['first_name' => 'Bob', 'last_name' => null]), $words, [true, false]);
    examEnter($this->teacherA, $this->a);

    $component = Livewire::test(ViewExam::class, ['record' => $this->e1->id])->callAction('csv');
    $component->assertFileDownloaded('exam-center-a-'.$this->e1->id.'-2026-11-05.csv');

    $response = $component->effects['download'] ?? null;
    expect($response)->not->toBeNull();
    $csv = base64_decode((string) $response['content']);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain("Место;Ученик;Верно;Всего;\"Результат, %\";Баллы\r\n")
        ->and($csv)->toContain("1;\"'=HYPERLINK(\"\"x\"\")\";2;2;100;20\r\n")
        ->and($csv)->toContain("2;Bob;1;2;50;10\r\n");
});
