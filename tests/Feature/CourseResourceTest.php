<?php

declare(strict_types=1);

use App\Filament\Resources\CourseResource;
use App\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Filament\Resources\CourseResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\TelegramGroupResource\Pages\EditTelegramGroup;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    $this->admin->assignRole('admin');
});

/** A course in `$status` with $words words, all translated into ru + uz. */
function adminCourse(string $status = 'draft', int $words = 4, int $withUz = 4): Course
{
    $course = Course::factory()->create(['status' => $status]);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    foreach (Word::factory()->count($words)->for($lesson)->create() as $i => $word) {
        WordTranslation::factory()->create(['word_id' => $word->id, 'locale' => 'ru']);
        if ($i < $withUz) {
            WordTranslation::factory()->uz()->create(['word_id' => $word->id]);
        }
    }

    return $course;
}

it('is closed to teachers', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $this->actingAs($teacher)->get('/admin/courses')->assertForbidden();
    expect(CourseResource::canViewAny())->toBeFalse();
});

it('lists courses for an admin', function (): void {
    $course = adminCourse();

    $this->actingAs($this->admin);

    livewire(ListCourses::class)->assertOk()->assertCanSeeTableRecords([$course]);
});

it('creates a draft platform course with titles in every language', function (): void {
    $this->actingAs($this->admin);

    livewire(CreateCourse::class)
        ->fillForm([
            'slug' => 'english-a1',
            'title' => ['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1'],
            'description' => ['ru' => 'Для начинающих', 'uz' => 'Boshlovchilar uchun'],
            'level' => 'A1',
            'exam_tag' => 'general',
            'visibility' => 'public',
            'position' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $course = Course::query()->where('slug', 'english-a1')->sole();
    expect($course->status)->toBe('draft')
        ->and($course->owner_type)->toBe('platform')
        ->and($course->title)->toBe(['ru' => 'Английский A1', 'uz' => 'Ingliz tili A1'])
        ->and($course->level)->toBe('A1');
});

it('requires the title in both languages and a valid unique slug', function (): void {
    Course::factory()->create(['slug' => 'taken']);
    $this->actingAs($this->admin);

    livewire(CreateCourse::class)
        ->fillForm(['slug' => 'Not Valid', 'title' => ['ru' => 'Курс', 'uz' => '']])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'regex', 'title.uz' => 'required']);

    livewire(CreateCourse::class)
        ->fillForm(['slug' => 'taken', 'title' => ['ru' => 'Курс', 'uz' => 'Kurs']])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

it('cannot change the status or the slug through the edit form', function (): void {
    $course = adminCourse('draft');
    $slug = $course->slug;
    $this->actingAs($this->admin);

    livewire(EditCourse::class, ['record' => $course->getRouteKey()])
        ->set('data.status', 'published')
        ->set('data.slug', 'hijacked')
        ->set('data.title.ru', 'Новое имя')
        ->call('save')
        ->assertHasNoFormErrors();

    $course->refresh();
    expect($course->status)->toBe('draft')
        ->and($course->slug)->toBe($slug)
        ->and($course->title['ru'])->toBe('Новое имя');
});

it('publishes a course through the table actions, step by step, with an audit trail', function (): void {
    $course = adminCourse('draft');
    $this->actingAs($this->admin);

    livewire(ListCourses::class)
        ->assertTableActionVisible('transition_review', $course)
        ->assertTableActionHidden('transition_published', $course)
        ->callTableAction('transition_review', $course)
        ->assertNotified();

    expect($course->fresh()->status)->toBe('review');

    livewire(ListCourses::class)
        ->assertTableActionHidden('transition_review', $course)
        ->assertTableActionVisible('transition_published', $course)
        ->callTableAction('transition_published', $course)
        ->assertNotified('Course is now published');

    $course->refresh();
    expect($course->status)->toBe('published')
        ->and($course->published_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'course.status_changed')->pluck('meta')->pluck('to')->all())
        ->toBe(['review', 'published']);

    livewire(ListCourses::class)
        ->assertTableActionVisible('transition_archived', $course)
        ->assertTableActionHidden('transition_published', $course);
});

it('keeps the course in review and explains why when too few words have uz', function (): void {
    $course = adminCourse('review', words: 10, withUz: 5);
    $this->actingAs($this->admin);

    livewire(ListCourses::class)
        ->callTableAction('transition_published', $course)
        ->assertNotified('Status not changed');

    expect($course->fresh()->status)->toBe('review')
        ->and(AuditLog::query()->where('action', 'course.status_changed')->count())->toBe(0);
});

it('offers the same status buttons on the edit page', function (): void {
    $course = adminCourse('review');
    $this->actingAs($this->admin);

    livewire(EditCourse::class, ['record' => $course->getRouteKey()])
        ->assertActionVisible('transition_published')
        ->assertActionHidden('transition_review')
        ->callAction('transition_published')
        ->assertNotified('Course is now published');

    expect($course->fresh()->status)->toBe('published');
});

it('manages the stages of a course and keeps stage numbers unique per course', function (): void {
    $course = Course::factory()->create();
    $other = Course::factory()->create();
    Stage::factory()->create(['course_id' => $other->id, 'number' => 1]);
    Stage::factory()->create(['course_id' => $course->id, 'number' => 2]);
    $this->actingAs($this->admin);

    $manager = livewire(StagesRelationManager::class, ['ownerRecord' => $course, 'pageClass' => EditCourse::class]);

    // 1 is taken in ANOTHER course only: allowed. 2 is taken here: rejected.
    $manager->callTableAction('create', data: ['number' => 1, 'title' => 'One'])->assertHasNoTableActionErrors();
    $manager->callTableAction('create', data: ['number' => 2, 'title' => 'Dup'])->assertHasTableActionErrors(['number' => 'unique']);

    expect(Stage::query()->where('course_id', $course->id)->pluck('number')->sort()->values()->all())->toBe([1, 2]);
});

it('lets an admin pick the course of a group', function (): void {
    $course = Course::factory()->published()->create(['slug' => 'ielts']);
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $this->actingAs($this->admin);

    livewire(EditTelegramGroup::class, ['record' => $group->getRouteKey()])
        ->fillForm(['course_id' => $course->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($group->fresh()->course_id)->toBe($course->id);
});
