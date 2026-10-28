<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\Word;
use App\Models\WordRepetition;
use App\Models\WordTranslation;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoContentSeeder;
use Database\Seeders\DemoGroupSeeder;
use Database\Seeders\DemoRepetitionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

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

it('seeds the demo modules without a name of their own, and a re-seed leaves a name the admin gave one', function (): void {
    $this->seed(DemoContentSeeder::class);

    // NULL, not the English "Stage 1": readers are shown "Модуль 1" / "1-modul" in their language (docs/14 §8).
    expect(Stage::query()->orderBy('number')->pluck('title', 'number')->all())->toBe([1 => null, 2 => null]);

    Stage::query()->where('number', 1)->update(['title' => 'Начальный уровень']);
    $this->seed(DemoContentSeeder::class);

    expect(Stage::query()->orderBy('number')->pluck('title', 'number')->all())->toBe([1 => 'Начальный уровень', 2 => null]);
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

it('seeds demo repetitions as lessons the groups already taught, so a Free demo learner still has the full new-word quota today', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(WordRepetition::query()->count())->toBeGreaterThan(0)
        ->and(WordRepetition::query()->where('introduced_mode', '!=', 'group_lesson')->count())->toBe(0)
        ->and(WordRepetition::query()->where('created_at', '>', now()->subDays(8))->count())->toBe(0)
        ->and(WordRepetition::query()->where('created_at', '<=', now()->subDays(30)->subMinute())->count())->toBe(0);

    foreach (Learner::query()->get() as $learner) {
        $token = app(JwtService::class)->issue($learner->id)['token'];

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/twa/today')
            ->assertOk()
            ->assertJsonPath('new_words_remaining', 20);
    }

    // Running the seeder again keeps the history in the past (idempotent, deterministic).
    $this->seed(DemoRepetitionsSeeder::class);
    expect(WordRepetition::query()->where('created_at', '>', now()->subDays(8))->count())->toBe(0);
});

it('skips demo seeders (no admin@local) outside local/testing but still seeds roles', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.demo_seed' => false]);

    app(DatabaseSeeder::class)->run();

    expect(User::query()->where('email', 'admin@local')->exists())->toBeFalse()
        ->and(Role::query()->where('name', 'admin')->exists())->toBeTrue();
});

it('runs demo seeders in production only with APP_DEMO_SEED=true', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.demo_seed' => true]);

    app(DatabaseSeeder::class)->run();

    expect(User::query()->where('email', 'admin@local')->exists())->toBeTrue();
});
