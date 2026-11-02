<?php

declare(strict_types=1);

use App\Domain\Administration\Services\AdminDashboardStats;
use App\Domain\Administration\Services\PanelDataScope;
use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Administration\Services\WidgetCache;
use App\Domain\Billing\Services\BillingReportService;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The cache of the dashboard widgets of /admin (docs/19 K1, docs/20 F5 / A12): every aggregate is an array of scalars on the
 * pure store, FRESH for a while and then served stale while it is recomputed after the response (`Cache::flexible`), keyed by
 * what the viewer may see; the money widgets move to a new data version with a payment or a refund; the stats widgets do not poll.
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

    // The format version comes before the parameters (docs/19 §3 rule 3); the money widgets carry the data version of the ledger.
    $money = 'all:n'.(int) Cache::get('admin:widget:money:ver', 0);

    foreach ([
        'admin:widget:total_learners:v1:all',
        'admin:widget:exams_30d:v1:all',
        "admin:widget:activity_chart:v1:all:{$today}",
        'admin:widget:top_learners:v1:all',
        'admin:widget:hardest_words:v1:all',
        'admin:widget:league_week:v1:all',
        "admin:widget:league_groups:v1:all:w{$week->id}:p1:n10",
        'admin:widget:organization_seats:v1:all:p1:n10',
        "admin:widget:revenue_30d:v1:{$money}",
        "admin:widget:webhook_errors:v1:{$money}",
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

it('keeps the money widgets one commit behind at most: a payment and a refund move them to a new data version, the others stay', function (): void {
    seedDashboardData();
    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('29 000 UZS');
    Livewire::test(WebhookErrorsWidget::class)->assertOk();
    Livewire::test(TotalLearnersWidget::class)->assertOk();

    $widgets = app(WidgetCache::class);
    $scope = $widgets->moneyScope();

    // A new payment: the version moves on, so both money entries are read at a key nobody has written yet; the learners' one stays.
    $charge = Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);

    expect($widgets->moneyScope())->not->toBe($scope)
        ->and(PureCache::store()->has(WidgetCache::key('revenue_30d', $widgets->moneyScope())))->toBeFalse()
        ->and(PureCache::store()->has(WidgetCache::key('webhook_errors', $widgets->moneyScope())))->toBeFalse()
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

it('does not let a rolled-back payment move the money widgets to a new version', function (): void {
    seedDashboardData();
    Livewire::test(RevenueLast30DaysWidget::class)->assertOk();
    $scope = app(WidgetCache::class)->moneyScope();

    try {
        DB::transaction(function (): void {
            Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(app(WidgetCache::class)->moneyScope())->toBe($scope, 'nothing was written, nothing to drop')
        ->and(PureCache::store()->has(WidgetCache::key('revenue_30d', $scope)))->toBeTrue();
});

it('does not keep the old figure of a reader that was still computing when the payment committed', function (): void {
    seedDashboardData(); // 29 000 UZS in the ledger
    $widgets = app(WidgetCache::class);
    $stats = app(AdminDashboardStats::class);

    // The reader makes the key at the version before the payment and computes the figure from the ledger as it is...
    $before = $widgets->moneyScope();
    $widgets->remember('revenue_30d', $before, 120, function (): array {
        $billing = app(BillingReportService::class);
        $figure = ['cash' => $billing->cashFlowLast30Days(), 'cohort' => $billing->cohortRevenueLast30Days()];

        // ...and the payment commits before the reader has written it (its observer moves the version on after the commit).
        Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);

        return $figure;
    });

    // The late write went to the key of the old version, which nobody reads any more: the next open sees the payment.
    // (A delete of the key at the commit would have come BEFORE that write and the old figure would have stood for the whole window.)
    expect($widgets->moneyScope())->not->toBe($before)
        ->and($stats->revenue30d()['cash']['UZS']['net'])->toBe(3_900_000)
        ->and($stats->revenue30d()['cash']['UZS']['charge_count'])->toBe(2);

    Livewire::test(RevenueLast30DaysWidget::class)->assertSee('39 000 UZS')->assertDontSee('29 000 UZS');
});

it('keeps the data version of the money on the default store, which never evicts, and moves it on only after the commit', function (): void {
    $widgets = app(WidgetCache::class);

    expect(WidgetCache::MONEY_VERSION_KEY)->toBe('admin:widget:money:ver')
        ->and($widgets->moneyScope())->toBe('all:n0');

    $widgets->forgetMoney();
    expect((int) Cache::get(WidgetCache::MONEY_VERSION_KEY))->toBe(1)
        ->and($widgets->moneyScope())->toBe('all:n1');

    // Inside the transaction (and for a reader beside it) the version is still the old one: a reader that computes now would
    // write a figure without the payment under the key of the old version, which is exactly where the payment is not yet.
    $inside = null;
    DB::transaction(function () use ($widgets, &$inside): void {
        Payment::factory()->create(['provider' => 'click', 'currency' => 'UZS', 'amount' => 1_000_000]);
        $inside = $widgets->moneyScope();
    });

    expect($inside)->toBe('all:n1')
        ->and($widgets->moneyScope())->toBe('all:n2');
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

it('serves the league table only the page sizes it offers and the first 1000 pages: a forged number is the same entry, not a new one', function (): void {
    $week = LeagueWeek::factory()->startingOn(app(LeagueService::class)->weekStart(CarbonImmutable::now())->toDateString())->create();
    LeagueGroup::factory()->count(12)->create(['league_week_id' => $week->id, 'tier' => 0, 'members_count' => 3]);

    $stats = app(AdminDashboardStats::class);
    $key = fn (int|string $page, int|string $perPage): string => WidgetCache::key('league_groups', 'all', "w{$week->id}:p{$page}:n{$perPage}");
    $first = $stats->leagueGroups($week->id, 1, 10);

    // A size nobody offers is the default size: ten rows and the one more (never all twelve, as `limit 1000001` would give),
    // read from the entry of the default size and with no entry of its own.
    foreach ([1_000_000, 0, -3, 7, 11] as $forged) {
        $rows = null;
        $queries = QueryLog::during(function () use ($stats, $week, $forged, &$rows): void {
            $rows = $stats->leagueGroups($week->id, 1, $forged);
        });

        expect($rows)->toBe($first, "size {$forged}")
            ->and($queries)->toBe([], "size {$forged} reads the entry of the default size")
            ->and(PureCache::store()->has($key(1, $forged)))->toBeFalse("no key of its own for size {$forged}");
    }

    // The sizes the widget offers are entries of their own.
    expect($stats->leagueGroups($week->id, 1, 25))->toHaveCount(12)
        ->and($stats->leagueGroups($week->id, 1, 50))->toHaveCount(12)
        ->and(PureCache::store()->has($key(1, 25)))->toBeTrue()
        ->and(PureCache::store()->has($key(1, 50)))->toBeTrue();

    // A page before the first is the first; a page past the last one served is that last one, whatever number was sent.
    expect($stats->leagueGroups($week->id, 0, 10))->toBe($first)
        ->and($stats->leagueGroups($week->id, -4, 10))->toBe($first);

    foreach ([1001, 5_000_000, PHP_INT_MAX] as $page) {
        expect($stats->leagueGroups($week->id, $page, 10))->toBe([])
            ->and(PureCache::store()->has($key($page, 10)))->toBeFalse("no key of its own for page {$page}");
    }

    expect(PureCache::store()->has($key(AdminDashboardStats::MAX_PAGE, 10)))->toBeTrue('they all share the entry of the last page');
});

it('serves the organization seats table only the page sizes it offers and the first 1000 pages', function (): void {
    Organization::factory()->count(12)->create();

    $stats = app(AdminDashboardStats::class);
    $key = fn (int|string $page, int|string $perPage): string => WidgetCache::key('organization_seats', 'all', "p{$page}:n{$perPage}");
    $first = $stats->organizationSeats(1, 10);

    expect($first)->toHaveCount(11, 'ten and the one that says there is more');

    // 50 and a million are not among the sizes of this widget (10 and 25): the default size, from the entry of the default size.
    foreach ([50, 1_000_000, 0, -1] as $forged) {
        $rows = null;
        $queries = QueryLog::during(function () use ($stats, $forged, &$rows): void {
            $rows = $stats->organizationSeats(1, $forged);
        });

        expect($rows)->toBe($first, "size {$forged}")
            ->and($queries)->toBe([])
            ->and(PureCache::store()->has($key(1, $forged)))->toBeFalse("no key of its own for size {$forged}");
    }

    expect($stats->organizationSeats(1, 25))->toHaveCount(12)
        ->and(PureCache::store()->has($key(1, 25)))->toBeTrue()
        ->and($stats->organizationSeats(0, 10))->toBe($first);

    foreach ([1001, 5_000_000] as $page) {
        expect($stats->organizationSeats($page, 10))->toBe([])
            ->and(PureCache::store()->has($key($page, 10)))->toBeFalse("no key of its own for page {$page}");
    }

    expect(PureCache::store()->has($key(AdminDashboardStats::MAX_PAGE, 10)))->toBeTrue();
});

it('answers a forged page size or page of the table widgets with an offered size and a served page', function (string $widget, array $sizes): void {
    $week = LeagueWeek::factory()->startingOn(app(LeagueService::class)->weekStart(CarbonImmutable::now())->toDateString())->create();
    LeagueGroup::factory()->count(12)->create(['league_week_id' => $week->id, 'tier' => 0, 'members_count' => 3]);
    Organization::factory()->count(12)->create();

    $component = Livewire::test($widget);
    $table = $component->instance()->getTable();

    // The table offers the sizes of the service and enforces them: `tableRecordsPerPage` is a public Livewire property.
    expect($table->enforcesPaginationPageOptions())->toBeTrue()
        ->and($table->getPaginationPageOptions())->toBe($sizes);

    foreach (['all', 1_000_000, 0, -1, 7, 'DROP'] as $forged) {
        $component->set('tableRecordsPerPage', $forged);

        expect($component->instance()->getTableRecordsPerPage())->toBe(10)
            ->and($component->instance()->getTableRecords()->count())->toBe(10, "size {$forged}");
    }

    // A page number that no list has: the last page the widget serves, which is empty here, with no key of its own.
    $component->set('tableRecordsPerPage', 10)->call('gotoPage', 5_000_000, 'page');

    expect($component->instance()->getTableRecords()->count())->toBe(0);

    $prefix = $widget === LeagueGroupsTableWidget::class ? "w{$week->id}:" : '';
    $name = $widget === LeagueGroupsTableWidget::class ? 'league_groups' : 'organization_seats';
    expect(PureCache::store()->has(WidgetCache::key($name, 'all', "{$prefix}p5000000:n10")))->toBeFalse()
        ->and(PureCache::store()->has(WidgetCache::key($name, 'all', "{$prefix}p1000:n10")))->toBeTrue()
        ->and(PureCache::store()->has(WidgetCache::key($name, 'all', "{$prefix}p1:n1000000")))->toBeFalse();
})->with([
    'league groups' => [LeagueGroupsTableWidget::class, AdminDashboardStats::LEAGUE_GROUPS_PAGE_SIZES],
    'organization seats' => [OrganizationSeatsTableWidget::class, AdminDashboardStats::ORGANIZATION_SEATS_PAGE_SIZES],
]);

it('switches the polling off on every stats and chart widget and keeps all the widgets lazy', function (): void {
    seedDashboardData();

    foreach (Filament::getPanel('admin')->getWidgets() as $widget) {
        expect($widget::isLazyLoaded())->toBeTrue(class_basename($widget).' must load lazily');

        Livewire::test($widget)->assertOk()->assertDontSeeHtml('wire:poll');
    }
});
