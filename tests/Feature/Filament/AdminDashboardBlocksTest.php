<?php

declare(strict_types=1);

use App\Domain\Administration\Services\DashboardOverview;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\StudyTodayStats;
use App\Domain\Analytics\Services\WeeklyActivityStats;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\CentersFirstAssignmentWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Filament\Widgets\FirstDayWidget;
use App\Filament\Widgets\ProPathWidget;
use App\Filament\Widgets\RetentionTrendWidget;
use App\Filament\Widgets\WeeklyActivityWidget;
use App\Jobs\WarmOpenSpeedJob;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The blocks of the tab «Учёба» of /admin (the design of 2026-11): the KPI cards, the first day of a cohort, D7 by week, the centers
 * to their first assignment, the path to Pro with Stars and the answers by week. Each reads cached figures of a domain service and
 * picks from them (DashboardOverview); the numbers here are worked out by hand.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    // Friday 2026-11-20, 13:00 in Tashkent.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

/** A day row of a learner, with the moment its first activity was recorded. */
function adb_day(string $date, int $reviews, string $createdUtc, ?string $goalMetUtc = null, int $newWords = 0): void
{
    DB::table('learner_daily_stats')->insert([
        'learner_id' => Learner::factory()->create()->id,
        'date' => (new LearnerDailyStats)->fromDateTime($date),
        'reviews_count' => $reviews,
        'new_words_count' => $newWords,
        'goal_met_at' => $goalMetUtc,
        'created_at' => $createdUtc,
        'updated_at' => $createdUtc,
    ]);
}

/** A cohort of the retention report as the cache keeps it. @param  array<string, mixed>  $extra */
function adb_cohort(string $monday, ?array $d7, ?array $activation, ?array $firstDay, array $extra = []): array
{
    return [
        'week_start' => $monday,
        'week_end' => CarbonImmutable::parse($monday)->addDays(6)->toDateString(),
        'current' => false,
        'size' => 10,
        'onboarded' => 0,
        'started' => 0,
        'started_pct' => null,
        'retention' => ['d1' => null, 'd3' => null, 'd7' => $d7, 'd14' => null, 'd30' => null, 'within' => null],
        'game' => ['d1' => null, 'd7' => null],
        'activation' => $activation ?? ['all' => null, 'ru' => null, 'uz' => null, 'solo' => null, 'class' => null],
        'first_day' => $firstDay,
        ...$extra,
    ];
}

/** Puts `$value` under a widget entry as a fresh `flexible` entry. */
function adb_cache(string $widget, array $value, string $params = ''): void
{
    $key = WidgetCache::key($widget, 'all', $params);
    PureCache::store()->put($key, $value, 600);
    PureCache::store()->put(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key, time(), 600);
}

/** The retention report of three weeks: the current one unknown, the last one with D7 31.5 %, the one before with 34 %. */
function adb_retention(): void
{
    adb_cache('retention', [
        'generated_at' => now()->toIso8601String(),
        'cohorts' => [
            adb_cohort('2026-11-16', null, null, null),
            adb_cohort('2026-11-09', null, [
                'all' => ['count' => 188, 'of' => 308, 'pct' => 61.0],
                'ru' => ['count' => 120, 'of' => 188, 'pct' => 63.8],
                'uz' => ['count' => 60, 'of' => 104, 'pct' => 57.7],
                'solo' => ['count' => 150, 'of' => 262, 'pct' => 57.3],
                'class' => null,
            ], ['size' => 308, 'answered' => 188, 'opened' => 43, 'onboarded' => 34, 'rest' => 43]),
            adb_cohort('2026-11-02', ['count' => 63, 'of' => 200, 'pct' => 31.5], null, null),
            adb_cohort('2026-10-26', ['count' => 68, 'of' => 200, 'pct' => 34.0], null, null),
        ],
        'segments' => ['weeks' => 4, 'locale' => [], 'trial' => []],
        'first_session' => ['round_again' => null],
    ]);
}

// --- «Учились сегодня» ---------------------------------------------------------------------------------------------

it('counts who studied today, who met the goal, and who had studied by this hour a week ago', function (): void {
    adb_day('2026-11-20', 5, '2026-11-20 03:00:00', '2026-11-20 04:00:00');
    adb_day('2026-11-20', 2, '2026-11-20 05:00:00');
    adb_day('2026-11-20', 9, '2026-11-20 07:00:00', '2026-11-20 07:30:00');
    adb_day('2026-11-20', 0, '2026-11-20 06:00:00');               // a game only: not studying
    adb_day('2026-11-13', 4, '2026-11-13 02:00:00');               // a week ago, before this hour
    adb_day('2026-11-13', 4, '2026-11-13 09:00:00');               // a week ago, later in that day: not by this hour
    adb_day('2026-11-19', 4, '2026-11-19 09:00:00');               // yesterday

    $figures = app(StudyTodayStats::class)->figures();

    expect($figures)->toBe(['date' => '2026-11-20', 'studied' => 3, 'goal_met' => 2, 'week_ago' => 1])
        ->and(StudyTodayStats::change(3, 1))->toBe(200)
        ->and(StudyTodayStats::change(3, 0))->toBeNull()
        ->and(PureCache::store()->has('admin:widget:study_today:v1:all:2026-11-20'))->toBeTrue();
});

it('reads the day of Tashkent: at 20:00 UTC it is already tomorrow there', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 20:00:00', 'UTC'));
    adb_day('2026-11-21', 3, '2026-11-20 19:30:00');

    expect(app(StudyTodayStats::class)->figures()['studied'])->toBe(1)
        ->and(app(StudyTodayStats::class)->figures()['date'])->toBe('2026-11-21');
});

it('casts the figures back to numbers when the cache hands them over as strings', function (): void {
    adb_cache('study_today', ['date' => '2026-11-20', 'studied' => '7', 'goal_met' => '2', 'week_ago' => '5'], '2026-11-20');

    expect(app(StudyTodayStats::class)->figures())->toBe(['date' => '2026-11-20', 'studied' => 7, 'goal_met' => 2, 'week_ago' => 5]);
});

// --- the KPI cards ------------------------------------------------------------------------------------------------

it('picks the latest week whose activation and D7 are known, with the change and the 95 % interval', function (): void {
    adb_retention();
    $overview = app(DashboardOverview::class);

    $activation = $overview->activation();
    $day7 = $overview->returnDay7();

    expect($activation['week_start'])->toBe('2026-11-09')
        ->and($activation['all'])->toBe(['count' => 188, 'of' => 308, 'pct' => 61.0])
        ->and($activation['class'])->toBeNull()
        ->and($day7['week_start'])->toBe('2026-11-02')
        ->and($day7['cell']['pct'])->toBe(31.5)
        ->and($day7['change'])->toBe(-2.5)
        ->and($day7['low'])->toBeLessThan(31.5)->and($day7['high'])->toBeGreaterThan(31.5)
        ->and($overview->day7Trend())->toHaveCount(2)
        ->and(DashboardOverview::day7Average($overview->day7Trend()))->toBe(32.8);
});

it('renders the four KPI cards in the words of the design', function (): void {
    adb_retention();
    adb_day('2026-11-20', 5, '2026-11-20 03:00:00', '2026-11-20 04:00:00');
    adb_day('2026-11-20', 5, '2026-11-20 03:00:00');
    adb_day('2026-11-13', 5, '2026-11-13 03:00:00');

    Livewire::test(DashboardKpiWidget::class)
        ->assertOk()
        ->assertSeeInOrder(['Учились сегодня', '2', '+100 % к прошлой пятнице', 'из них выполнили цель дня 1'])
        ->assertSeeInOrder(['Активация за 24 часа', '61%', '188 из 308', 'ru 64 % · uz 58 % · класс —'])
        ->assertSeeInOrder(['Возврат на 7-й день', '32%', '−2,5 п.п.', 'когорта 2–8 ноября', '95 %:'])
        ->assertSeeInOrder(['Требуют внимания', '0', 'срочных нет']);
});

it('shows dashes, not zeros, while no week is known', function (): void {
    Livewire::test(DashboardKpiWidget::class)
        ->assertOk()
        ->assertSee('нет недели, у которой прошли первые сутки')
        ->assertSee('Ни у одной недели седьмой день ещё не прошёл.')
        ->assertSee('неделю назад к этому часу — никого');

    Livewire::test(FirstDayWidget::class)->assertSee('Нет недели регистраций, у которой прошли первые сутки.');
    Livewire::test(RetentionTrendWidget::class)->assertSee('Ни у одной недели седьмой день ещё не прошёл.');
});

// --- the first day and the open speed -----------------------------------------------------------------------------

it('draws the first day of the same cohort as the activation, with the p75 of the open speed by platform', function (): void {
    adb_retention();
    $learner = Learner::factory()->create();
    foreach ([['android', 2900], ['android', 2900], ['ios', 2100]] as [$platform, $ms]) {
        ProductEvent::query()->create([
            'name' => ProductEventName::ClientTiming->value,
            'learner_id' => $learner->id,
            'props' => ['open_id' => fake()->uuid(), 'platform' => $platform, 'button_ready' => $ms],
            'occurred_at' => now()->subDay(),
        ]);
    }

    app(DashboardOverview::class)->warmOpenSpeed();

    expect(app(DashboardOverview::class)->firstDay())->toMatchArray(['size' => 308, 'answered' => 188, 'opened' => 43, 'onboarded' => 34, 'rest' => 43])
        ->and(app(DashboardOverview::class)->openSpeed())->toMatchArray(['android' => ['n' => 2, 'p50' => 2900, 'p75' => 2900, 'p95' => 2900]]);

    Livewire::test(FirstDayWidget::class)
        ->assertOk()
        ->assertSee('Первый день новичка')
        ->assertSee('308 регистраций')
        ->assertSeeInOrder(['Ответили на слово', '188', 'Открыли занятие', '43', 'Прошли онбординг', '34', 'Только открыли', '43'])
        ->assertSee('Время до первой кнопки, p75: Android 2,9 с · iOS 2,1 с')
        ->assertSeeHtml('style="width: 61.04%"');
});

it('says when there are no timing marks', function (): void {
    adb_retention();
    app(DashboardOverview::class)->warmOpenSpeed();

    Livewire::test(FirstDayWidget::class)->assertSee('Время до первой кнопки: замеров за 7 дней нет');
});

/** One `client.timing` open of `$platform` with the button ready after `$ms`, a day ago. */
function adb_timing(string $platform, int $ms): void
{
    ProductEvent::query()->create([
        'name' => ProductEventName::ClientTiming->value,
        'learner_id' => Learner::factory()->create()->id,
        'props' => ['open_id' => fake()->uuid(), 'platform' => $platform, 'button_ready' => $ms],
        'occurred_at' => now()->subDay(),
    ]);
}

it('never scans the timing marks in the request of the widget: with no warmed entry it queues the job and says the figure is coming', function (): void {
    Queue::fake();
    adb_timing('android', 2900);

    $queries = QueryLog::during(fn () => Livewire::test(FirstDayWidget::class)
        ->assertOk()
        ->assertSee('Время до первой кнопки: замеров пока нет — расчёт запущен, обновите страницу через минуту')
        ->assertDontSee('замеров за 7 дней нет'));

    expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'product_events')))->toBe([])
        ->and(app(DashboardOverview::class)->openSpeed())->toBeNull();
    Queue::assertPushedOn('low', WarmOpenSpeedJob::class);
});

it('queues one warm-up however many opens find the entry missing', function (): void {
    Queue::fake();

    app(DashboardOverview::class)->openSpeed();
    app(DashboardOverview::class)->openSpeed();
    Livewire::test(FirstDayWidget::class)->assertOk();

    Queue::assertPushed(WarmOpenSpeedJob::class, 1);
});

it('writes the open speed in the job and the next open reads it without a query', function (): void {
    adb_timing('android', 2900);
    adb_timing('ios', 2100);

    app(WarmOpenSpeedJob::class)->handle(app(DashboardOverview::class));

    expect(PureCache::store()->get(DashboardOverview::timingKey()))->toMatchArray(['opens' => 2, 'truncated' => false])
        ->and(DashboardOverview::timingKey())->toBe('admin:widget:client_timing:v1:all:d7');

    $queries = QueryLog::during(fn () => expect(app(DashboardOverview::class)->openSpeed())->toBe([
        'android' => ['n' => 1, 'p50' => 2900, 'p75' => 2900, 'p95' => 2900],
        'ios' => ['n' => 1, 'p50' => 2100, 'p75' => 2100, 'p95' => 2100],
    ]));
    expect($queries)->toBe([]);

    // The entry lives three hours: the schedule rewrites it every 30 minutes, a missed run or two leave the figure.
    $this->travel(DashboardOverview::TIMING_TTL_SECONDS - 60)->seconds();
    expect(app(DashboardOverview::class)->openSpeed())->not->toBeNull();
    $this->travel(120)->seconds();
    Queue::fake();
    expect(app(DashboardOverview::class)->openSpeed())->toBeNull();
    Queue::assertPushed(WarmOpenSpeedJob::class);
});

it('warms the open speed on the schedule every 30 minutes, on the low queue', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => $e->description === 'analytics:warm-open-speed');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/30 * * * *');

    Queue::fake();
    $event->run(app());

    Queue::assertPushedOn('low', WarmOpenSpeedJob::class);
});

// --- D7 by week ---------------------------------------------------------------------------------------------------

it('draws D7 by week with the average dashed and the last week red when it fell', function (): void {
    adb_retention();

    Livewire::test(RetentionTrendWidget::class)
        ->assertOk()
        ->assertSee('Возврат на 7-й день, %')
        ->assertSee('- - среднее 33 %')
        ->assertSee('● ниже прошлой недели')
        ->assertSeeHtml('class="lx-chart-alert"')
        ->assertSeeHtml('class="lx-chart-average"')
        ->assertSeeHtml('role="img"');
});

// --- centers --------------------------------------------------------------------------------------------------------

it('lists the centers still waiting for a completed assignment first, then the latest that got there, with the median', function (): void {
    $waiting = Organization::factory()->create(['name' => 'Lingua Center', 'status' => 'active', 'created_at' => now()->subDays(9)]);
    Organization::factory()->create(['name' => 'Zukko School', 'status' => 'active', 'created_at' => now()->subDays(5)]);
    $done = Organization::factory()->create(['name' => 'English House', 'status' => 'active', 'created_at' => now()->subDays(20)]);
    AssignmentResult::factory()->create([
        'assignment_id' => Assignment::factory()->create(['organization_id' => $done->id])->id,
        'completed_at' => now()->subDays(18),
    ]);
    Organization::factory()->suspended()->create(['name' => 'Closed', 'created_at' => now()->subDays(40)]);

    $centers = app(DashboardOverview::class)->centers();

    expect(array_column($centers['rows'], 'name'))->toBe(['Lingua Center', 'Zukko School', 'English House'])
        ->and($centers['rows'][0])->toMatchArray(['id' => $waiting->id, 'waiting' => true])
        ->and($centers['median'])->toBe(2 * 86400);

    Livewire::test(CentersFirstAssignmentWidget::class)
        ->assertOk()
        ->assertSee('медиана 2 дн 0 ч')
        ->assertSeeInOrder(['Lingua Center', 'ждёт 9 дней', 'Zukko School', 'ждёт 5 дней', 'English House', 'за 2 дня'])
        ->assertSeeHtml('lx-pill-danger')
        ->assertSeeHtml('lx-pill-warning')
        ->assertSeeHtml('lx-pill-success')
        ->assertDontSee('Closed');
});

// --- the path to Pro --------------------------------------------------------------------------------------------------

it('shows the Stars row of the Pro funnel step by step, the studied share over the matured payers', function (): void {
    adb_cache('billing_funnel', [
        'generated_at' => now()->toIso8601String(),
        'days' => 30,
        'funnel' => [
            ['provider' => 'all', 'locale' => 'all', 'viewed' => 2140, 'started' => 300, 'paid' => 200, 'studied' => 150, 'maturing' => 10],
            ['provider' => 'telegram_stars', 'locale' => 'all', 'viewed' => 2140, 'started' => 182, 'paid' => 121, 'studied' => 88, 'maturing' => 25],
            ['provider' => 'telegram_stars', 'locale' => 'ru', 'viewed' => 1000, 'started' => 90, 'paid' => 60, 'studied' => 40, 'maturing' => 10],
        ],
        'outside' => [],
        'placements' => [],
        'orders' => [],
    ], 'd30');

    expect(app(DashboardOverview::class)->proPath())->toBe(['days' => 30, 'viewed' => 2140, 'started' => 182, 'paid' => 121, 'studied' => 88, 'matured' => 96]);

    Livewire::test(ProPathWidget::class)
        ->assertOk()
        ->assertSeeInOrder(['Путь к Pro · Stars', 'Увидели предложение', '2 140', 'Начали оплату', '182 · 8,5 %', 'Оплатили', '121 · 66,5 %', 'Занимались за 7 дней', '88 из 96 · 91,7 %'])
        ->assertSeeHtml('tab=money');
});

it('says nobody saw an offer when the funnel is empty', function (): void {
    Livewire::test(ProPathWidget::class)->assertOk()->assertSee('За 30 дней предложение Pro никто не видел.');
});

// --- answers by week --------------------------------------------------------------------------------------------------

it('adds the answers up by Monday-Sunday week of the last twelve, repetitions apart from new words', function (): void {
    adb_day('2026-11-20', 30, '2026-11-20 03:00:00', newWords: 10);   // this week (Nov 16)
    adb_day('2026-11-16', 20, '2026-11-16 03:00:00', newWords: 5);
    adb_day('2026-11-15', 40, '2026-11-15 03:00:00', newWords: 0);    // Sunday: the week of Nov 9
    adb_day('2026-08-30', 99, '2026-08-30 03:00:00');                 // the week of Aug 24: before the twelve

    $weeks = app(WeeklyActivityStats::class)->weeks();

    expect($weeks)->toHaveCount(12)
        ->and($weeks[0]['week_start'])->toBe('2026-08-31')
        ->and($weeks[11])->toBe(['week_start' => '2026-11-16', 'reviews' => 50, 'new_words' => 15])
        ->and($weeks[10])->toBe(['week_start' => '2026-11-09', 'reviews' => 40, 'new_words' => 0])
        ->and(array_sum(array_column($weeks, 'reviews')))->toBe(90);

    Livewire::test(WeeklyActivityWidget::class)
        ->assertOk()
        ->assertSee('Занятия по неделям')
        ->assertSee('ответов · 12 нед.')
        ->assertSee('повторения')
        ->assertSee('новые слова')
        ->assertSeeHtml('class="lx-bar-new"');
});

it('says there were no answers in twelve weeks', function (): void {
    Livewire::test(WeeklyActivityWidget::class)->assertOk()->assertSee('За 12 недель ответов не было.');
});

// --- cost and access --------------------------------------------------------------------------------------------------

it('runs no query on a warm open of any block', function (string $widget): void {
    adb_retention();
    adb_day('2026-11-20', 5, '2026-11-20 03:00:00');
    Organization::factory()->create(['status' => 'active', 'created_at' => now()->subDays(9)]);

    Livewire::test($widget)->assertOk();

    expect(QueryLog::during(fn () => Livewire::test($widget)->assertOk()))->toBe([]);
})->with([
    'kpi' => DashboardKpiWidget::class,
    'first day' => FirstDayWidget::class,
    'D7' => RetentionTrendWidget::class,
    'centers' => CentersFirstAssignmentWidget::class,
    'pro path' => ProPathWidget::class,
    'weekly' => WeeklyActivityWidget::class,
]);

it('keeps every block from a teacher', function (string $widget): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $this->actingAs($teacher);

    expect($widget::canView())->toBeFalse();
    Livewire::test($widget)->assertForbidden();
})->with([
    DashboardKpiWidget::class,
    FirstDayWidget::class,
    RetentionTrendWidget::class,
    CentersFirstAssignmentWidget::class,
    ProPathWidget::class,
    WeeklyActivityWidget::class,
]);
