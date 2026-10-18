<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordTranslation;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoGroupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the default course with all demo stages and ru translations', function (): void {
    $this->seed(DemoContentSeeder::class);

    $course = Course::query()->where('slug', config('content.default_course_slug'))->sole();

    expect($course->status)->toBe('published')
        ->and($course->visibility)->toBe('public')
        ->and($course->owner_type)->toBe('platform')
        ->and(ksorted($course->title))->toBe(ksorted(['ru' => 'Базовый курс', 'uz' => 'Asosiy kurs']))
        ->and($course->words_count)->toBe(120)
        ->and($course->stages()->count())->toBe(2)
        ->and(Stage::whereNull('course_id')->count())->toBe(0)
        ->and(Word::count())->toBe(120)
        ->and(WordTranslation::where('locale', 'ru')->where('source', 'import')->count())->toBe(120);
});

it('is idempotent', function (): void {
    $this->seed(DemoContentSeeder::class);
    $this->seed(DemoContentSeeder::class);

    expect(Course::count())->toBe(1)
        ->and(Stage::count())->toBe(2)
        ->and(Word::count())->toBe(120)
        ->and(WordTranslation::count())->toBe(120);
});

it('binds the demo group to the default course', function (): void {
    $this->seed(DemoContentSeeder::class);
    $this->seed(DemoGroupSeeder::class);

    $group = TelegramGroup::query()->where('chat_id', -1001234567890)->sole();

    expect($group->course?->slug)->toBe(config('content.default_course_slug'));
});

it('honours content.default_course_slug', function (): void {
    config(['content.default_course_slug' => 'custom']);

    $this->seed(DemoContentSeeder::class);

    expect(Course::query()->sole()->slug)->toBe('custom');
});
