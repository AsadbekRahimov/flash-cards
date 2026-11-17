<?php

declare(strict_types=1);

/*
 * A quiz of the week is a row of `exam_sessions` with no group and no lesson (docs/13 §8.5): /admin lists and opens it for an
 * administrator without a query per row and without failing on the empty group, and a teacher (whose scope is the groups) never sees it.
 */

use App\Filament\Resources\ExamSessionResource;
use App\Filament\Resources\ExamSessionResource\Pages\ListExamSessions;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function levelQuizAdminUser(): User
{
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    return $admin;
}

it('lists group exams by default, the quizzes and mocks by the mode filter, and opens a quiz session for an administrator', function (): void {
    $group = ExamSession::factory()->create();
    $quiz = ExamSession::factory()->levelQuiz()->create();
    $mock = ExamSession::factory()->personalMock()->create();

    $admin = levelQuizAdminUser();
    $this->actingAs($admin)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id]);

    // The default: the exams of the groups, as before the quizzes appeared.
    Livewire::test(ListExamSessions::class)->assertSuccessful()
        ->assertCanSeeTableRecords([$group])->assertCanNotSeeTableRecords([$quiz, $mock])
        ->assertTableColumnStateSet('mode', 'group_exam', $group)
        ->filterTable('mode', 'level_quiz')->assertCanSeeTableRecords([$quiz])->assertCanNotSeeTableRecords([$group, $mock])
        ->assertTableColumnFormattedStateSet('mode', 'Тест недели', $quiz)
        ->filterTable('mode', 'personal_mock')->assertCanSeeTableRecords([$mock])->assertCanNotSeeTableRecords([$group, $quiz])
        ->removeTableFilter('mode')->assertCanSeeTableRecords([$group, $quiz, $mock]);
    $this->get(ExamSessionResource::getUrl('view', ['record' => $quiz]))->assertOk();
});

it('does not show a quiz to a teacher, who sees the exams of their groups only', function (): void {
    $group = TelegramGroup::factory()->create();
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach($group->id, ['is_primary' => true]);

    $own = ExamSession::factory()->create(['telegram_group_id' => $group->id]);
    $quiz = ExamSession::factory()->levelQuiz(Learner::factory()->create())->create();

    $this->actingAs($teacher);

    expect(ExamSessionResource::getEloquentQuery()->pluck('id')->all())->toBe([$own->id])
        ->and($teacher->can('view', $quiz))->toBeFalse();
    $this->get(ExamSessionResource::getUrl('view', ['record' => $quiz]))->assertNotFound(); // outside the teacher scope: not found
});
