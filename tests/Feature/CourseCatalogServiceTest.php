<?php

declare(strict_types=1);

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HotPathFixtures as Fx;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

it('creates the default course once and reuses it', function (): void {
    $catalog = app(CourseCatalogService::class);

    $first = $catalog->defaultCourse();
    $second = $catalog->defaultCourse();

    expect($first->id)->toBe($second->id)
        ->and($first->slug)->toBe(config('content.default_course_slug'))
        ->and($first->status)->toBe('published')
        ->and($first->title)->toHaveKeys(['ru', 'uz'])
        ->and(Course::query()->count())->toBe(1);
});

it('uses the configured slug for the default course', function (): void {
    config(['content.default_course_slug' => 'base']);
    $existing = Course::factory()->published()->create(['slug' => 'base']);

    expect(app(CourseCatalogService::class)->defaultCourse()->id)->toBe($existing->id)
        ->and(Course::query()->count())->toBe(1);
});

it('gives a group its own course, or the default one when none is set', function (): void {
    $catalog = app(CourseCatalogService::class);
    $own = Course::factory()->create();

    $withCourse = TelegramGroup::factory()->create(['course_id' => $own->id]);
    $without = TelegramGroup::factory()->create(['course_id' => null]);

    expect($catalog->courseForGroup($withCourse)->id)->toBe($own->id)
        ->and($catalog->courseForGroup($without)->slug)->toBe(config('content.default_course_slug'));
});

it('finds stages and lessons inside the given course only', function (): void {
    $catalog = app(CourseCatalogService::class);
    $a = Course::factory()->create();
    $b = Course::factory()->create();
    $stageA = Stage::factory()->create(['course_id' => $a->id, 'number' => 1]);
    $stageB = Stage::factory()->create(['course_id' => $b->id, 'number' => 1]);
    $lessonB = Lesson::factory()->for($stageB)->create(['number' => 2]);

    expect($catalog->findStage($a, 1)?->id)->toBe($stageA->id)
        ->and($catalog->findStage($b, 1)?->id)->toBe($stageB->id)
        ->and($catalog->findStage($a, 9))->toBeNull()
        ->and($catalog->findLesson($stageA, 2))->toBeNull()
        ->and($catalog->findLesson($stageB, 2)?->id)->toBe($lessonB->id);
});

it('lists the lessons of a course in study order', function (): void {
    $catalog = app(CourseCatalogService::class);
    $course = Course::factory()->create();
    $other = Course::factory()->create();
    $s2 = Stage::factory()->create(['course_id' => $course->id, 'number' => 2]);
    $s1 = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    Lesson::factory()->for($s2)->create(['number' => 1]);
    Lesson::factory()->for($s1)->create(['number' => 2]);
    Lesson::factory()->for($s1)->create(['number' => 1]);
    Lesson::factory()->for(Stage::factory()->create(['course_id' => $other->id]))->create();

    $order = $catalog->lessons($course)->map(fn (Lesson $l): string => $l->stage->number.'.'.$l->number)->all();

    expect($order)->toBe(['1.1', '1.2', '2.1'])
        ->and($catalog->lessons($course, 2))->toHaveCount(2);
});

it('applies one availability rule to the predicate, the slug lookup and the query scope', function (string $status, string $visibility, bool $available): void {
    $course = Course::factory()->create(['slug' => 'rule', 'status' => $status, 'visibility' => $visibility]);
    $catalog = app(CourseCatalogService::class);

    expect($catalog->isAvailable($course))->toBe($available)
        ->and($catalog->findAvailable('rule')?->id)->toBe($available ? $course->id : null)
        ->and(Course::query()->available()->whereKey($course->id)->exists())->toBe($available);
})->with([
    'published public' => ['published', 'public', true],
    'published unlisted' => ['published', 'unlisted', true],
    'published private' => ['published', 'private', false],
    'draft public' => ['draft', 'public', false],
    'review public' => ['review', 'public', false],
    'archived unlisted' => ['archived', 'unlisted', false],
]);

// --- a lesson with its stage and course in one statement ---------------------------------------------------

it('reads a lesson with its stage and the course facts in ONE statement', function (): void {
    ['lessons' => $lessons] = Fx::course([3 => [5 => 2]], ['owner_type' => 'learner', 'owner_id' => 77, 'visibility' => 'private', 'source' => 'learner']);
    $id = $lessons['3.5']->id;
    $lesson = null;

    $queries = QueryLog::during(function () use (&$lesson, $id): void {
        $lesson = app(CourseCatalogService::class)->lessonWithContext($id);
    });

    $plain = Lesson::query()->with('stage.course')->findOrFail($id);
    expect($queries)->toHaveCount(1)
        // The lesson and its stage are the models a plain find gives, nothing of the join is left in their attributes ...
        ->and($lesson?->getAttributes())->toBe($plain->getAttributes())
        ->and($lesson?->stage?->getAttributes())->toBe($plain->stage?->getAttributes())
        ->and($lesson?->stage?->number)->toBe(3)
        // ... and the course carries exactly the access facts, which agree with the real row.
        ->and(array_keys($lesson?->stage?->course?->getAttributes() ?? []))->toBe(['id', 'slug', 'owner_type', 'owner_id', 'visibility', 'status'])
        ->and($lesson?->stage?->course?->id)->toBe($plain->stage?->course?->id)
        ->and($lesson?->stage?->course?->owner_type)->toBe('learner')
        ->and($lesson?->stage?->course?->owner_id)->toBe(77)
        ->and($lesson?->stage?->course?->visibility)->toBe('private')
        ->and($lesson?->stage?->course?->isLearnerOwned())->toBeTrue()
        ->and($lesson?->stage?->course?->exists)->toBeTrue()
        ->and($lesson?->relationLoaded('stage'))->toBeTrue()
        ->and($lesson?->stage?->relationLoaded('course'))->toBeTrue();
});

it('answers null for a lesson that does not exist', function (): void {
    expect(app(CourseCatalogService::class)->lessonWithContext(999_999))->toBeNull();
});
