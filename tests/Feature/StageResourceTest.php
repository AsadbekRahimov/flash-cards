<?php

declare(strict_types=1);

use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\StageResource\Pages\CreateStage;
use App\Filament\Resources\StageResource\Pages\EditStage;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('creates a stage inside the selected course', function (): void {
    $course = Course::factory()->create();

    livewire(CreateStage::class)
        ->fillForm(['course_id' => $course->id, 'number' => 7, 'title' => 'Seven'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Stage::query()->where('number', 7)->value('course_id'))->toBe($course->id);
});

it('requires a course', function (): void {
    livewire(CreateStage::class)
        ->fillForm(['course_id' => null, 'number' => 1, 'title' => 'No course'])
        ->call('create')
        ->assertHasFormErrors(['course_id' => 'required']);
});

it('allows the same stage number in another course but not twice in one course', function (): void {
    $first = Course::factory()->create();
    $second = Course::factory()->create();
    Stage::factory()->create(['course_id' => $first->id, 'number' => 1]);

    livewire(CreateStage::class)
        ->fillForm(['course_id' => $first->id, 'number' => 1, 'title' => 'Dup'])
        ->call('create')
        ->assertHasFormErrors(['number' => 'unique']);

    livewire(CreateStage::class)
        ->fillForm(['course_id' => $second->id, 'number' => 1, 'title' => 'Other course'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Stage::query()->where('number', 1)->count())->toBe(2);
});

it('does not let an edit move a stage to another course', function (): void {
    $first = Course::factory()->create();
    $second = Course::factory()->create();
    $stage = Stage::factory()->create(['course_id' => $first->id, 'number' => 1]);

    livewire(EditStage::class, ['record' => $stage->getKey()])
        ->assertFormFieldIsDisabled('course_id')
        ->fillForm(['course_id' => $second->id, 'title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($stage->fresh()?->course_id)->toBe($first->id)
        ->and($stage->fresh()?->title)->toBe('Renamed');
});

it('does not let an edit move a lesson to a stage of another course', function (): void {
    $stage = Stage::factory()->create();
    $otherStage = Stage::factory()->create();
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => 1]);

    livewire(EditLesson::class, ['record' => $lesson->getKey()])
        ->assertFormFieldIsDisabled('stage_id')
        ->fillForm(['stage_id' => $otherStage->id, 'title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($lesson->fresh()?->stage_id)->toBe($stage->id)
        ->and($lesson->fresh()?->title)->toBe('Renamed');
});
