<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\ExamSessionResource;
use App\Filament\Resources\StageResource;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\TelegramGroupResource;
use App\Filament\Resources\UserResource;
use App\Models\ExamSession;
use App\Models\Student;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('limits teacher resources and dashboard queries to assigned groups', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher->telegramGroups()->attach($ownGroup->id, ['is_primary' => true]);

    $ownStudent = Student::factory()->create(['telegram_group_id' => $ownGroup->id]);
    $otherStudent = Student::factory()->create(['telegram_group_id' => $otherGroup->id]);
    $ownReview = TrainingReview::factory()->create(['student_id' => $ownStudent->id]);
    TrainingReview::factory()->create(['student_id' => $otherStudent->id]);
    $ownExam = ExamSession::factory()->create(['telegram_group_id' => $ownGroup->id]);
    ExamSession::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->actingAs($teacher);

    expect(TelegramGroupResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownGroup->id]);
    expect(StudentResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownStudent->id]);
    expect(ExamSessionResource::getEloquentQuery()->pluck('id')->all())->toBe([$ownExam->id]);

    $scope = app(PanelDataScope::class);
    expect($scope->trainingReviews()->pluck('id')->all())->toBe([$ownReview->id]);
    expect($scope->exams()->pluck('id')->all())->toBe([$ownExam->id]);

    expect(UserResource::canViewAny())->toBeFalse();
    expect(StageResource::canViewAny())->toBeTrue();
    expect(StageResource::canCreate())->toBeFalse();

    $this->get(ExamSessionResource::getUrl('view', ['record' => $ownExam]))->assertOk();
});

it('does not let a teacher open a student from another group', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher->telegramGroups()->attach($ownGroup->id);
    $otherStudent = Student::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->actingAs($teacher)
        ->get(StudentResource::getUrl('view', ['record' => $otherStudent]))
        ->assertNotFound();
});

it('does not let a teacher open an exam log from another group', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $ownGroup = TelegramGroup::factory()->create();
    $otherGroup = TelegramGroup::factory()->create();
    $teacher->telegramGroups()->attach($ownGroup->id);
    $otherExam = ExamSession::factory()->create(['telegram_group_id' => $otherGroup->id]);

    $this->actingAs($teacher)
        ->get(ExamSessionResource::getUrl('view', ['record' => $otherExam]))
        ->assertNotFound();
});
