<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Filament\Widgets\OrganizationSeatsTableWidget;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Filament\Widgets\WebhookErrorsWidget;
use App\Models\ExamSession;
use App\Models\LeagueGroup;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The cache of the dashboard widgets of /admin (docs/19 K1, docs/20 F5 / A12): every aggregate is an array of scalars on the
 * pure store, FRESH for a while and then served stale while it is recomputed after the response (`Cache::flexible`), keyed by
 * what the viewer may see; the money widgets are dropped by a payment or a refund; the stats widgets do not poll.
 * The tests run on the configured pure store (an array store here, the second Redis in CI); the one that moves the clock uses an array store.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RolePermissionSeeder::class)->run();

    $this->admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $this->admin->assignRole('admin');

    Auth::login($this->admin);
    Session::put('2fa.web.passed_at', now()->timestamp);
    Session::put('2fa.web.user_id', $this->admin->id);
});

/** Something for every widget of the dashboard to compute: learners, reviews, an exam, a center, a league week, money. */
function seedDashboardData(): void
{
    $group = TelegramGroup::factory()->create(['title' => 'Class 1']);
    $learner = Learner::factory()->memberOf($group)->create(['first_name' => 'Ada', 'last_seen_at' => now()->subDay()]);
    Learner::factory()->count(2)->create();

    $word = Word::factory()->create(['word' => 'difficult']);
    $session = TrainingSession::factory()->create(['telegram_group_id' => $group->id]);
    TrainingReview::factory()->count(5)->create([
        'training_session_id' => $session->id,
        'learner_id' => $learner->id,
        'word_id' => $word->id,
        'quality' => 1,
        'created_at' => now(),
    ]);

    ExamSession::factory()->create(['telegram_group_id' => $group->id, 'status' => 'open', 'started_at' => now()->subDay()]);

    $organization = Organization::factory()->create(['name' => 'Center A']);
    Subscription::factory()->forOrganization($organization)->create(['current_period_ends_at' => now()->addDays(10), 'tier' => 'tutor', 'seats_limit' => 30]);
    Learner::factory()->seatedIn($organization)->create();

    $week = LeagueWeek::factory()->startingOn(app(LeagueService::class)->weekStart(CarbonImmutable::now())->toDateString())->create();
    LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'members_count' => 4]);

    Payment::factory()->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 2_900_000]);
}

it('opens the dashboard of /admin a second time without a single query from its widgets', function (): void {
    seedDashboardData();
    $widgets = Filament::getPanel('admin')->getWidgets();

    expect($widgets)->toHaveCount(12);

    // The first open computes and stores: it is where the queries are.
    $cold = [];
    foreach ($widgets as $widget) {
        $cold[$widget] = QueryLog::during(fn () => Livewire::test($widget)->assertOk());
    }

    expect(count($cold[TopLearnersTableWidget::class]))->toBeGreaterThan(0)
        ->and(count($cold[HardestWordsTableWidget::class]))->toBeGreaterThan(0)
        ->and(count($cold[TotalLearnersWidget::class]))->toBeGreaterThan(0);

    // The second one reads the cache: not one statement, from any of the twelve.
    foreach ($widgets as $widget) {
        $queries = QueryLog::during(fn () => Livewire::test($widget)->assertOk());

        expect($queries)->toBe([], class_basename($widget).' ran: '.implode(' | ', $queries));
    }
});

it('shows the same dashboard from the cache as from the database', function (): void {
    seedDashboardData();

    $fresh = [
        TotalLearnersWidget::class => ['Всего учеников', 'Активны за 7 дней'],
        TopLearnersTableWidget::class => ['Ada', 'Class 1'],
        HardestWordsTableWidget::class => ['difficult', '100%'],
        OrganizationSeatsTableWidget::class => ['Center A', '30'],
        LeagueGroupsTableWidget::class => ['Серебро (1)', '4 / 30'],
        RevenueLast30DaysWidget::class => ['29 000 UZS'],
    ];

    foreach ($fresh as $widget => $texts) {
        $first = Livewire::test($widget);
        $second = Livewire::test($widget);

        foreach ($texts as $text) {
            $first->assertSee($text);
            $second->assertSee($text);
        }
    }
});

it('keeps each aggregate under a versioned key of its own, with the viewer in it', function (): void {
    seedDashboardData();
    $today = now()->toDateString();
    $week = LeagueWeek::query()->firstOrFail();

    foreach ([TotalLearnersWidget::class, ExamsLast30DaysWidget::class, ActivityChartWidget::class, TopLearnersTableWidget::class, HardestWordsTableWidget::class, LeagueGroupsTableWidget::class, OrganizationSeatsTableWidget::class, RevenueLast30DaysWidget::class, WebhookErrorsWidget::class] as $widget) {
        Livewire::test($widget)->assertOk();
    }

    foreach ([
        'admin:widget:total_learners:v1:all',
        'admin:widget:exams_30d:v1:all',
        "admin:widget:activity_chart:v1:all:{$today}",
        'admin:widget:top_learners:v1:all',
        'admin:widget:hardest_words:v1:all',
        'admin:widget:league_week:v1:all',
        "admin:widget:league_groups:w{$week->id}:p1:n10:v1:all",
        'admin:widget:organization_seats:p1:n10:v1:all',
        'admin:widget:revenue_30d:v1:all',
        'admin:widget:webhook_errors:v1:all',
    ] as $key) {
        expect(PureCache::store()->has($key))->toBeTrue($key);
        // Only arrays and scalars: nothing the store may refuse to unserialize (cache.serializable_classes = false).
        expect(PureCache::store()->get($key))->toBeArray();
    }
});

it('serves the figure of the last computation until the refresh, which runs after the response', function (): void {
    config(['cache.pure_store' => 'array']); // the clock of the test moves the clock of an array store only
    Learner::factory()->create();
    $stats = app(AdminDashboardStats::class);

    expect($stats->totalLearners()['total'])->toBe(1);

    Learner::factory()->create();
    expect($stats->totalLearners()['total'])->toBe(1, 'inside the freshness window the cached figure stands');

    // Past the 120 s of freshness: the old figure is still what the reader gets, the new one is computed afterwards.
    $this->travel(130)->seconds();
    expect($stats->totalLearners()['total'])->toBe(1, 'stale, served while the refresh waits');

    app(DeferredCallbackCollection::class)->invoke();
    expect($stats->totalLearners()['total'])->toBe(2, 'the deferred refresh has stored the new figure');

    // Past the whole lifetime of an entry (five times the freshness): nothing is left to serve, it is computed inline.
    Learner::factory()->create();
    $this->travel(700)->seconds();
    expect($stats->totalLearners()['total'])->toBe(3);
});

it('refreshes a stale entry under a lock that is let go, on whatever store the pure cache is (the second Redis in CI)', function (): void {
    Learner::factory()->create();
    $stats = app(AdminDashboardStats::class);
    $created = Repository::FLEXIBLE_CREATED_KEY_PREFIX.WidgetCache::key('total_learners', 'all');

    expect($stats->totalLearners()['total'])->toBe(1);

    // "Computed an hour ago": past the freshness, inside the lifetime. No clock is moved, so a Redis store takes part.
    foreach ([2, 3] as $expected) {
        PureCache::store()->put($created, time() - 3600, 600);
        Learner::factory()->create();

        expect($stats->totalLearners()['total'])->toBe($expected - 1, 'the stale figure is served first');

        app(DeferredCallbackCollection::class)->invoke();

        expect($stats->totalLearners()['total'])->toBe($expected, 'then the refresh, which took the lock and let it go');
    }
});

it('keeps the money widgets one commit behind at most: a payment and a refund drop them, the others stay', function (): void {
    seedDashboardData();
    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('29 000 UZS');
    Livewire::test(WebhookErrorsWidget::class)->assertOk();
    Livewire::test(TotalLearnersWidget::class)->assertOk();

    // A new payment: both money entries go, the learners' one does not.
    $charge = Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);

    expect(PureCache::store()->has(WidgetCache::key('revenue_30d', 'all')))->toBeFalse()
        ->and(PureCache::store()->has(WidgetCache::key('webhook_errors', 'all')))->toBeFalse()
        ->and(PureCache::store()->has(WidgetCache::key('total_learners', 'all')))->toBeTrue();

    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('39 000 UZS')->assertDontSee('29 000 UZS');

    // A refund: the charge flips and the refund row is booked, as PaymentApplier does.
    $charge->forceFill(['status' => Payment::STATUS_REFUNDED])->save();
    Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000, 'kind' => Payment::KIND_REFUND, 'refunded_payment_id' => $charge->id]);

    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('29 000 UZS')->assertSee('10 000 UZS');

    // A payment that fails shows in the errors of the webhooks at once.
    Payment::factory()->failed()->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 500_000]);
    Livewire::test(WebhookErrorsWidget::class)->assertSee('Payme: 1');
});

it('does not let a rolled-back payment drop the money widgets', function (): void {
    seedDashboardData();
    Livewire::test(RevenueLast30DaysWidget::class)->assertOk();

    try {
        DB::transaction(function (): void {
            Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(PureCache::store()->has(WidgetCache::key('revenue_30d', 'all')))->toBeTrue('nothing was written, nothing to drop');
});

it('gives a teacher the figures of their own groups, never the cached ones of the admin or of another teacher', function (): void {
    $groupA = TelegramGroup::factory()->create(['title' => 'Class A']);
    $groupB = TelegramGroup::factory()->create(['title' => 'Class B']);
    Learner::factory()->memberOf($groupA)->count(1)->create();
    Learner::factory()->memberOf($groupB)->count(3)->create();
    Learner::factory()->count(2)->create();

    $teacherA = User::factory()->create(['is_active' => true]);
    $teacherA->assignRole('teacher');
    $teacherA->telegramGroups()->attach($groupA->id);
    $teacherB = User::factory()->create(['is_active' => true]);
    $teacherB->assignRole('teacher');
    $teacherB->telegramGroups()->attach($groupB->id);

    $scope = app(PanelDataScope::class);

    // The admin goes first and fills the cache with the whole platform: 6 learners.
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(6);

    Auth::login($teacherA);
    $keyA = WidgetCache::key('total_learners', $scope->fingerprint());
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(1);

    Auth::login($teacherB);
    $keyB = WidgetCache::key('total_learners', $scope->fingerprint());
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(3);

    Auth::login($this->admin);
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(6);

    // One entry per viewer, each with the viewer in its key.
    $keys = [WidgetCache::key('total_learners', 'all'), $keyA, $keyB];
    expect(array_unique($keys))->toHaveCount(3)
        ->and($keyA)->toContain((string) $teacherA->id)
        ->and($keyB)->toContain((string) $teacherB->id);

    foreach ($keys as $key) {
        expect(PureCache::store()->has($key))->toBeTrue($key);
    }
});

it('changes the key of a teacher the moment their groups change, so a lost group is never shown from the cache', function (): void {
    $groupA = TelegramGroup::factory()->create();
    $groupB = TelegramGroup::factory()->create();
    Learner::factory()->memberOf($groupA)->create();
    Learner::factory()->memberOf($groupB)->count(2)->create();

    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $teacher->telegramGroups()->attach([$groupA->id, $groupB->id]);
    Auth::login($teacher);

    $scope = app(PanelDataScope::class);
    $both = $scope->fingerprint();
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(3);

    // Group B is taken away by an administrator (another request): no waiting for the TTL, the numbers of B are gone with
    // the teacher's next request. The groups are read once per request (PanelRequestMemo); the flush stands for its end.
    $teacher->telegramGroups()->detach($groupB->id);
    app(PanelRequestMemo::class)->flush();

    expect($scope->fingerprint())->not->toBe($both)
        ->and(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(1);

    // Put back: the old key, still fresh, is read again.
    $teacher->telegramGroups()->attach($groupB->id);
    app(PanelRequestMemo::class)->flush();
    expect($scope->fingerprint())->toBe($both);
});

it('does not cache for a visitor nobody signed in', function (): void {
    Learner::factory()->count(2)->create();
    Auth::logout();

    expect(app(PanelDataScope::class)->fingerprint())->toBe('none')
        ->and(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(0);

    // Whatever was read for nobody is not left behind for somebody.
    Auth::login($this->admin);
    expect(app(AdminDashboardStats::class)->totalLearners()['total'])->toBe(2);
});

it('pages the cached tables in the simple mode: each page and page size is an entry, with one row more to tell there is a next page', function (): void {
    $week = LeagueWeek::factory()->startingOn(app(LeagueService::class)->weekStart(CarbonImmutable::now())->toDateString())->create();
    LeagueGroup::factory()->count(12)->create(['league_week_id' => $week->id, 'tier' => 0, 'members_count' => 3]);

    $stats = app(AdminDashboardStats::class);
    $first = $stats->leagueGroups($week->id, 1, 10);
    $second = $stats->leagueGroups($week->id, 2, 10);
    $third = $stats->leagueGroups($week->id, 3, 10);

    expect($first)->toHaveCount(11, 'ten and the one that says there is more')
        ->and($second)->toHaveCount(2)
        ->and($third)->toBe([])
        ->and(array_slice(array_column($first, 'id'), 10))->toBe([array_column($second, 'id')[0]]);

    $component = Livewire::test(LeagueGroupsTableWidget::class);
    $component->assertSee('групп — 12');
    expect($component->instance()->getTableRecords()->count())->toBe(10)
        ->and($component->instance()->getTableRecords()->hasMorePages())->toBeTrue();

    $component->call('gotoPage', 2, 'page');
    expect($component->instance()->getTableRecords()->count())->toBe(2)
        ->and($component->instance()->getTableRecords()->hasMorePages())->toBeFalse();
});

it('switches the polling off on every stats and chart widget and keeps all the widgets lazy', function (): void {
    seedDashboardData();

    foreach (Filament::getPanel('admin')->getWidgets() as $widget) {
        expect($widget::isLazyLoaded())->toBeTrue(class_basename($widget).' must load lazily');

        Livewire::test($widget)->assertOk()->assertDontSeeHtml('wire:poll');
    }
});
