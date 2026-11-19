<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Services\ExerciseMixStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\ExerciseMixTableWidget;
use App\Models\Learner;
use App\Models\TrainingReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * Audit 2026-11-18 («Что делать ученику в разные моменты», row 2): the share of each exercise format among the answered cards of
 * the last 7 days, for everybody and by interface language, in one grouped statement and through the widget cache. The same file
 * runs on sqlite and on PostgreSQL.
 */

uses(RefreshDatabase::class);

const EMS_NOW = '2026-11-18 10:00:00';

beforeEach(function (): void {
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse(EMS_NOW, 'UTC'));
});

function ems_answers(Learner $learner, string $exercise, int $count, string $atUtc = '2026-11-17 10:00:00'): void
{
    TrainingReview::factory()->count($count)->create(['learner_id' => $learner->id, 'exercise' => $exercise, 'created_at' => $atUtc]);
}

function ems_compute(): array
{
    return app(ExerciseMixStats::class)->compute(CarbonImmutable::parse(EMS_NOW, 'UTC'));
}

it('shares the answered cards of 7 days by format, for everybody and by interface language', function (): void {
    $ru = Learner::factory()->create(['interface_locale' => 'ru']);
    $uz = Learner::factory()->create(['interface_locale' => 'uz']);
    ems_answers($ru, 'flash', 2);
    ems_answers($ru, 'audio', 2);
    ems_answers($uz, 'flash', 3);
    ems_answers($uz, 'meaning', 1);
    ems_answers($uz, 'audio', 1, '2026-11-11 09:59:00');             // older than 7 days: not counted
    ems_answers($ru, 'audio', 1, '2026-11-11 10:00:00');             // exactly 7 days ago: counted

    $figures = ems_compute();
    $rows = collect($figures['rows'])->keyBy('exercise');

    expect($figures['totals'])->toBe(['all' => 9, 'ru' => 5, 'uz' => 4])
        // The order of ExerciseType: flash, meaning, ..., audio.
        ->and(array_column($figures['rows'], 'exercise'))->toBe(['flash', 'meaning', 'audio'])
        ->and($rows['flash']['all'])->toBe(['count' => 5, 'of' => 9, 'pct' => 55.6])
        ->and($rows['flash']['ru'])->toBe(['count' => 2, 'of' => 5, 'pct' => 40.0])
        ->and($rows['flash']['uz'])->toBe(['count' => 3, 'of' => 4, 'pct' => 75.0])
        // Audio vanished in the Uzbek interface: 0 of 4, not a dash.
        ->and($rows['audio']['ru'])->toBe(['count' => 3, 'of' => 5, 'pct' => 60.0])
        ->and($rows['audio']['uz'])->toBe(['count' => 0, 'of' => 4, 'pct' => 0.0])
        ->and($rows['meaning']['ru'])->toBe(['count' => 0, 'of' => 5, 'pct' => 0.0]);
});

it('has a dash for a language with no answered card and nothing at all for an empty week', function (): void {
    expect(ems_compute())->toBe(['totals' => ['all' => 0, 'ru' => 0, 'uz' => 0], 'rows' => []]);

    ems_answers(Learner::factory()->create(['interface_locale' => 'ru']), 'spelling', 2);
    $row = ems_compute()['rows'][0];

    expect($row['exercise'])->toBe('spelling')
        ->and($row['ru'])->toBe(['count' => 2, 'of' => 2, 'pct' => 100.0])
        ->and($row['uz'])->toBeNull();
});

it('keeps a format the code does not know after the known ones', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru']);
    ems_answers($learner, 'flash', 1);
    ems_answers($learner, 'pretest', 1);
    // A value written before a format was renamed: the model casts to the enum, so it is put in by SQL.
    DB::table('training_reviews')->where('exercise', 'flash')->update(['exercise' => 'legacy_x']);

    expect(array_column(ems_compute()['rows'], 'exercise'))->toBe(['pretest', 'legacy_x']);
});

it('runs one statement for few and for many answers', function (): void {
    $learners = Learner::factory()->count(2)->sequence(['interface_locale' => 'ru'], ['interface_locale' => 'uz'])->create();
    ems_answers($learners[0], 'flash', 2);
    $few = QueryLog::during(fn () => ems_compute());

    foreach (['meaning', 'reverse', 'audio', 'gap_fill'] as $exercise) {
        ems_answers($learners[1], $exercise, 5);
    }
    $many = QueryLog::during(fn () => ems_compute());

    expect($few)->toHaveCount(1)->and($many)->toHaveCount(1);
});

it('reads through the widget cache and casts numbers stored as strings back', function (): void {
    ems_answers(Learner::factory()->create(['interface_locale' => 'uz']), 'audio', 2);
    $stats = app(ExerciseMixStats::class);

    $first = $stats->lastWeek();
    expect(QueryLog::during(fn () => $stats->lastWeek()))->toBe([]);

    $key = WidgetCache::key(ExerciseMixStats::WIDGET, 'all');
    expect($key)->toBe('admin:widget:exercise_mix:v1:all');
    $stored = PureCache::store()->get($key);
    $stored['totals']['all'] = '2';
    $stored['rows'][0]['uz'] = ['count' => '2', 'of' => '2', 'pct' => '100'];
    PureCache::store()->put($key, $stored, 600);

    expect($stats->lastWeek())->toBe($first);
});

it('shows the widget to an active admin only and opens it a second time without a query', function (): void {
    app(RolePermissionSeeder::class)->run();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    ems_answers(Learner::factory()->create(['interface_locale' => 'ru']), 'audio', 3);
    ems_answers(Learner::factory()->create(['interface_locale' => 'uz']), 'flash', 1);

    $this->actingAs($teacher);
    expect(ExerciseMixTableWidget::canView())->toBeFalse();

    $this->actingAs($admin);
    expect(ExerciseMixTableWidget::canView())->toBeTrue();

    Livewire::test(ExerciseMixTableWidget::class)
        ->assertOk()
        ->assertSee('Форматы заданий — 7 дней')
        ->assertSee('На слух')
        ->assertSee('100,0 % (3 из 3)')
        ->assertSee('0,0 % (0 из 1)');

    expect(QueryLog::during(fn () => Livewire::test(ExerciseMixTableWidget::class)->assertOk()))
        ->each->not->toContain('training_reviews');
});
