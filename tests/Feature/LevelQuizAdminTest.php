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

it('lists and opens a quiz session for an administrator', function (): void {
    $quiz = ExamSession::factory()->levelQuiz()->create();
    ExamSession::factory()->personalMock()->create();

    $admin = levelQuizAdminUser();
    $this->actingAs($admin)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id]);

    Livewire::test(ListExamSessions::class)->assertSuccessful()->assertCanSeeTableRecords([$quiz]);
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
