<?php

declare(strict_types=1);

use App\Domain\Content\Services\CourseCatalogService;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TelegramGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
