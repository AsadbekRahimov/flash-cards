<?php

declare(strict_types=1);

use App\Filament\Resources\StageResource\Pages\CreateStage;
use App\Models\Course;
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
