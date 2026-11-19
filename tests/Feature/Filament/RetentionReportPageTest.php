<?php

declare(strict_types=1);

use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Analytics\Services\RetentionReportService;
use App\Domain\Analytics\Support\WilsonInterval;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Pages\RetentionReport;
use App\Filament\Resources\Support\AdminNumbers;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

use Tests\Support\QueryLog;

/*
 * The page «Удержание» of /admin: platform data for an active admin only, a thin page over RetentionReportService, a number
 * of queries that does not depend on the number of learners, a refresh button, and a cache that hands numbers back as strings.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
});

function rtp_user(string $role, bool $active = true): User
{
    $user = User::factory()->create([
        'is_active' => $active,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

function rtp_fill(int $learners): void
{
    foreach (range(1, $learners) as $i) {
        $learner = Learner::factory()->create([
            'created_at' => '2026-11-0'.(3 + $i % 4).' 06:00:00',
            'timezone' => ['Asia/Tashkent', 'America/New_York', 'Europe/London'][$i % 3],
            'interface_locale' => $i % 2 === 0 ? 'uz' : 'ru',
        ]);
        LearnerDailyStats::query()->create(['learner_id' => $learner->id, 'date' => '2026-11-0'.(4 + $i % 4), 'reviews_count' => 2]);
    }
}

it('opens for an active admin and for nobody else', function (): void {
    $admin = rtp_user('admin');
    $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id])
        ->get('/admin/retention')->assertOk()->assertSee('Удержание');

    foreach ([rtp_user('teacher'), rtp_user('admin', active: false)] as $user) {
        $this->actingAs($user)
            ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
            ->get('/admin/retention')->assertForbidden();
    }

    auth()->logout();
    $this->get('/admin/retention')->assertRedirect();
});

it('is refused to a teacher at mount, and is in the menu of an admin only', function (): void {
    $this->actingAs(rtp_user('teacher'));
    livewire(RetentionReport::class)->assertForbidden();
    expect(RetentionReport::canAccess())->toBeFalse();

    $this->actingAs(rtp_user('admin'));
    expect(RetentionReport::canAccess())->toBeTrue()
        ->and(RetentionReport::getNavigationGroup())->toBe(__('admin.nav.administration'));
});

it('shows the cohorts, the first session, the slice and the notes of how to read them', function (): void {
    rtp_fill(6);
    $this->actingAs(rtp_user('admin'));

    livewire(RetentionReport::class)
        ->assertOk()
        ->assertSee('Когорты по неделе регистрации')
        ->assertSee('02.11.2026')
        ->assertSee('Первое занятие')
        ->assertSee('Срез по языку и пробному Pro')
        ->assertSee('Интерфейс: узбекский')
        ->assertSee('D1 около 14 %')
        ->assertSee('Активация за 24 часа')
        ->assertSee('Самостоятельно')
        ->assertSee('Учёба и игра: D1 и D7')
        ->assertSee('Практика здесь не показана');
});

it('shows "count / of" under every percent and the Wilson interval of a small base', function (): void {
    rtp_fill(6);
    $this->actingAs(rtp_user('admin'));

    // The week of Nov 2 has six learners, each studied on day 1..4 of it; the D1 cell of that week is some k of 6 with its interval.
    $cohort = collect(app(RetentionReportService::class)->report()['cohorts'])->firstWhere('week_start', '2026-11-02');
    $d1 = $cohort['retention']['d1'];
    $interval = WilsonInterval::of($d1['count'], $d1['of']);

    livewire(RetentionReport::class)
        ->assertOk()
        ->assertSee($d1['count'].' / '.$d1['of'])
        ->assertSee('95 %: '.AdminNumbers::decimal($interval['low'], 1).'–'.AdminNumbers::decimal($interval['high'], 1));
});

it('runs the same number of queries for 5 learners and for 60', function (): void {
    $this->actingAs(rtp_user('admin'));
    livewire(RetentionReport::class); // loads what is per process (permissions, the panel)

    $render = function (): int {
        app(RetentionReportService::class)->forget();

        return count(QueryLog::during(fn () => livewire(RetentionReport::class)->assertOk()));
    };

    rtp_fill(5);
    $few = $render();
    rtp_fill(55);
    $many = $render();

    expect($few)->toBe($many);

    // A second open of the page is served by the cache: the report adds no query of its own.
    $cached = count(QueryLog::during(fn () => livewire(RetentionReport::class)->assertOk()));
    expect($cached)->toBeLessThan($few - 3);
});

it('recomputes on «Обновить»', function (): void {
    $this->actingAs(rtp_user('admin'));
    $page = livewire(RetentionReport::class)->assertOk();

    rtp_fill(3);
    expect(array_sum(array_column(app(RetentionReportService::class)->report()['cohorts'], 'size')))->toBe(0);

    $page->callAction('refresh');

    expect(array_sum(array_column(app(RetentionReportService::class)->report()['cohorts'], 'size')))->toBe(3);
});

it('does not fail when the cache hands numbers back as strings', function (): void {
    rtp_fill(3);
    $this->actingAs(rtp_user('admin'));
    app(RetentionReportService::class)->report();

    $key = WidgetCache::key(RetentionReportService::CACHE_WIDGET, 'all');
    $stored = PureCache::store()->get($key);
    foreach ($stored['cohorts'] as $i => $cohort) {
        $stored['cohorts'][$i]['size'] = (string) $cohort['size'];
        $stored['cohorts'][$i]['onboarded'] = (string) $cohort['onboarded'];
        $stored['cohorts'][$i]['started'] = (string) $cohort['started'];
    }
    $stored['first_session']['registered'] = (string) $stored['first_session']['registered'];
    $stored['first_session']['in_progress'] = (string) $stored['first_session']['in_progress'];
    $stored['segments']['locale'][0]['size'] = (string) $stored['segments']['locale'][0]['size'];
    PureCache::store()->put($key, $stored, 600);

    livewire(RetentionReport::class)->assertOk()->assertSee('Когорты по неделе регистрации');
});
