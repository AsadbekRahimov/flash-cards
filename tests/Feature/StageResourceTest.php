<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\LessonResource\Pages\CreateLesson;
use App\Filament\Resources\LessonResource\Pages\EditLesson;
use App\Filament\Resources\LessonResource\Pages\ListLessons;
use App\Filament\Resources\StageResource\Pages\CreateStage;
use App\Filament\Resources\StageResource\Pages\EditStage;
use App\Filament\Resources\StageResource\Pages\ListStages;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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

// --- 17.14-S: a module may have no name of its own (docs/14 §6.5, §8) ---------------------------------------------

it('lets a module go without a name: an empty field is stored as NULL, and the hint says what learners then see', function (?string $title): void {
    $course = Course::factory()->create();

    livewire(CreateStage::class)
        ->assertSee('Если оставить пустым, ученики увидят «Модуль N»')
        ->fillForm(['course_id' => $course->id, 'number' => 3, 'title' => $title])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Stage::query()->where('number', 3)->sole()->title)->toBeNull();
})->with([
    'an empty field' => [''],
    'spaces only' => ['   '],
    'no value' => [null],
]);

it('keeps a name that was given, and clears it again when the field is emptied', function (): void {
    $course = Course::factory()->create();

    livewire(CreateStage::class)
        ->fillForm(['course_id' => $course->id, 'number' => 4, 'title' => 'Образование'])
        ->call('create')
        ->assertHasNoFormErrors();
    $stage = Stage::query()->where('number', 4)->sole();
    expect($stage->title)->toBe('Образование');

    livewire(EditStage::class, ['record' => $stage->getKey()])
        ->assertSet('data.title', 'Образование')
        ->fillForm(['title' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($stage->fresh()?->title)->toBeNull();
});

it('names a module without a title "Модуль N" in the list of modules and in the modules tab of a course', function (): void {
    $course = Course::factory()->create();
    $untitled = Stage::factory()->untitled()->create(['course_id' => $course->id, 'number' => 3]);
    $named = Stage::factory()->create(['course_id' => $course->id, 'number' => 4, 'title' => 'Образование']);

    livewire(ListStages::class)
        ->assertCanSeeTableRecords([$untitled, $named])
        ->assertTableColumnStateSet('title', 'Модуль 3', $untitled)
        ->assertTableColumnStateSet('title', 'Образование', $named)
        ->assertSee('Модуль 3');

    livewire(StagesRelationManager::class, ['ownerRecord' => $course, 'pageClass' => EditCourse::class])
        ->assertTableColumnStateSet('title', 'Модуль 3', $untitled)
        ->assertTableColumnStateSet('title', 'Образование', $named)
        ->callTableAction('create', data: ['number' => 5, 'title' => ''])
        ->assertHasNoTableActionErrors();

    expect(Stage::query()->where('course_id', $course->id)->where('number', 5)->sole()->title)->toBeNull();
});

it('names the module of a lesson "Модуль N" when it has no title: in the list, in the filter and in the select of the form', function (): void {
    $untitled = Stage::factory()->untitled()->create(['number' => 4]);
    $named = Stage::factory()->create(['number' => 5, 'title' => 'Образование']);
    $inUntitled = Lesson::factory()->create(['stage_id' => $untitled->id, 'number' => 1]);
    $inNamed = Lesson::factory()->create(['stage_id' => $named->id, 'number' => 1]);

    livewire(ListLessons::class)
        ->assertCanSeeTableRecords([$inUntitled, $inNamed])
        ->assertTableColumnStateSet('stage.title', 'Модуль 4', $inUntitled)
        ->assertTableColumnStateSet('stage.title', 'Образование', $inNamed)
        ->filterTable('stage_id', $untitled->id)
        ->assertCanSeeTableRecords([$inUntitled])
        ->assertCanNotSeeTableRecords([$inNamed]);

    // The select is searched on the server: a LIKE on the title alone would never offer the module that has none.
    livewire(CreateLesson::class)
        ->assertFormFieldExists('stage_id', fn (Select $field): bool => array_values($field->getSearchResults('')) === ['Модуль 4', 'Образование']
            && array_values($field->getSearchResults('Образ')) === ['Образование']
            && array_values($field->getSearchResults('4')) === ['Модуль 4']
            && array_values($field->getSearchResults('Модуль 4')) === ['Модуль 4']
            && $field->getSearchResults('нет такого') === []);

    livewire(EditLesson::class, ['record' => $inUntitled->getKey()])
        ->assertFormFieldExists('stage_id', fn (Select $field): bool => $field->getOptionLabel() === 'Модуль 4');
});

it('creates a lesson in a module that has no title, picked by its number', function (): void {
    $untitled = Stage::factory()->untitled()->create(['number' => 6]);

    livewire(CreateLesson::class)
        ->fillForm(['stage_id' => $untitled->id, 'number' => 1, 'title' => 'Greetings'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Lesson::query()->where('stage_id', $untitled->id)->sole()->title)->toBe('Greetings');
});

it('shows the module of a lesson in the lesson list without a query per row', function (): void {
    $lessons = collect(range(1, 6))->map(fn (int $n): Lesson => Lesson::factory()->create(['stage_id' => Stage::factory()->untitled()->create(['number' => $n])->id, 'number' => 1]));

    DB::flushQueryLog();
    DB::enableQueryLog();
    livewire(ListLessons::class)->assertCanSeeTableRecords($lessons);
    $stageReads = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql): bool => str_contains($sql, 'from "stages"') || str_contains($sql, 'from `stages`'));
    DB::disableQueryLog();

    // One read for the relation of the column (the page at once), one for the options of the module filter; the caption of
    // a blank title adds no read of its own - and the filter loads only what the caption needs.
    expect($stageReads->count())->toBe(2)
        ->and($stageReads->last())->toContain('"stages"."id", "stages"."number", "stages"."title"')
        ->and($stageReads->last())->not->toContain('description');
});
