<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AttentionWidget;
use App\Filament\Widgets\DashboardKpiWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\PlatformSubscriptionsWidget;
use App\Filament\Widgets\RevenueLast30DaysWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\TelegramGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\QueryLog;

/*
 * The home of /admin (the design of 2026-11): the switch «Учёба / Деньги» in the query string, only the widgets of the open tab on
 * the page (the other tab's widgets are not mounted, so their figures are not read), «Деньги» for an active admin only, and a page
 * whose own queries do not grow with the data and do not depend on the tab (docs/20 §1.1, §1.11).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'));
});

function adp_user(string $role, bool $active = true): User
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

/** GET of the dashboard as `$user`, past the 2FA gate. */
function adp_get(User $user, string $query = ''): Illuminate\Testing\TestResponse
{
    return test()->actingAs($user)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
        ->get('/admin'.$query);
}

/** Learners, their days, centers and money: something for every widget of both tabs to read. */
function adp_fill(int $learners): void
{
    foreach (range(1, $learners) as $i) {
        $learner = Learner::factory()->create(['created_at' => now()->subDays($i % 20)]);
        LearnerDailyStats::query()->create(['learner_id' => $learner->id, 'date' => now()->subDays($i % 5)->toDateString(), 'reviews_count' => 3]);
    }
    Organization::factory()->count(max(1, intdiv($learners, 10)))->create(['status' => 'active', 'created_at' => now()->subDays(9)]);
    Payment::factory()->count(max(1, intdiv($learners, 10)))->create(['provider' => 'payme', 'currency' => 'UZS', 'amount' => 1_000_000]);
}

it('is the home page of /admin, titled «Панель»', function (): void {
    expect(Filament::getPanel('admin')->getPages())->toContain(Dashboard::class)
        ->and(Dashboard::getUrl())->toEndWith('/admin');

    adp_get(adp_user('admin'))
        ->assertOk()
        ->assertSee('Панель')
        ->assertSee('Учёба')
        ->assertSee('Деньги')
        ->assertSee('Пятница, 20 ноября 2026');
});

it('opens «Учёба» by default and mounts only its widgets', function (): void {
    $this->actingAs(adp_user('admin'));

    $page = Livewire::test(Dashboard::class)->assertOk();

    expect($page->instance()->activeTab())->toBe(Dashboard::TAB_STUDY)
        ->and($page->instance()->getWidgets())->toBe(Dashboard::STUDY_WIDGETS);

    $page->assertSeeLivewire(DashboardKpiWidget::class)
        ->assertSeeLivewire(AttentionWidget::class)
        ->assertSeeLivewire(TotalLearnersWidget::class)
        ->assertDontSeeLivewire(RevenueLast30DaysWidget::class)
        ->assertDontSeeLivewire(PlatformSubscriptionsWidget::class)
        ->assertSeeHtml('aria-current="page"')
        ->assertSeeHtml('data-tab="money"');
});

it('opens «Деньги» from the query string, so a link shows the same tab, and mounts only its widgets', function (): void {
    $this->actingAs(adp_user('admin'));

    $page = Livewire::withQueryParams(['tab' => 'money'])->test(Dashboard::class)->assertOk();

    expect($page->instance()->activeTab())->toBe(Dashboard::TAB_MONEY)
        ->and($page->instance()->getWidgets())->toBe(Dashboard::MONEY_WIDGETS);

    $page->assertSeeLivewire(RevenueLast30DaysWidget::class)
        ->assertSeeLivewire(PlatformSubscriptionsWidget::class)
        ->assertDontSeeLivewire(DashboardKpiWidget::class)
        ->assertDontSeeLivewire(TotalLearnersWidget::class);

    // The switch links to both tabs: «Учёба» is the plain address, «Деньги» carries the tab.
    expect(Dashboard::getUrl(['tab' => 'money']))->toContain('tab=money');
    adp_get(adp_user('admin'), '?tab=money')->assertOk()->assertSeeHtml('href="'.e(Dashboard::getUrl(['tab' => 'money'])).'"');
});

it('falls back to «Учёба» for an unknown tab', function (): void {
    $this->actingAs(adp_user('admin'));

    expect(Livewire::withQueryParams(['tab' => 'secret'])->test(Dashboard::class)->instance()->activeTab())->toBe(Dashboard::TAB_STUDY);
});

it('gives a teacher «Учёба» with the widgets of their groups, no switch and no money, whatever the query says', function (): void {
    $teacher = adp_user('teacher');
    $teacher->telegramGroups()->attach(TelegramGroup::factory()->create()->id);
    $this->actingAs($teacher);

    $page = Livewire::withQueryParams(['tab' => 'money'])->test(Dashboard::class)->assertOk();

    expect($page->instance()->activeTab())->toBe(Dashboard::TAB_STUDY);
    $page->assertSeeLivewire(TotalLearnersWidget::class)
        ->assertSeeLivewire(TopLearnersTableWidget::class)
        ->assertSeeLivewire(HardestWordsTableWidget::class)
        ->assertDontSeeLivewire(DashboardKpiWidget::class)
        ->assertDontSeeLivewire(AttentionWidget::class)
        ->assertDontSeeLivewire(RevenueLast30DaysWidget::class)
        ->assertDontSeeHtml('data-testid="dashboard-tabs"');

    adp_get($teacher, '?tab=money')->assertOk()->assertDontSee('Деньги')->assertDontSee('Выручка за 30 дней');
});

it('does not let an inactive admin see the money tab', function (): void {
    $this->actingAs(adp_user('admin', active: false));

    expect(Livewire::withQueryParams(['tab' => 'money'])->test(Dashboard::class)->instance()->activeTab())->toBe(Dashboard::TAB_STUDY);
});

it('runs the same number of queries for a small and a larger platform, on either tab, within the budget', function (): void {
    $admin = adp_user('admin');
    adp_get($admin)->assertOk(); // warm-up: per-process work (roles, panel boot) is not the page's

    adp_fill(3);
    $small = QueryLog::during(fn () => adp_get($admin)->assertOk());
    $smallMoney = QueryLog::during(fn () => adp_get($admin, '?tab=money')->assertOk());

    adp_fill(40);
    $large = QueryLog::during(fn () => adp_get($admin)->assertOk());
    $largeMoney = QueryLog::during(fn () => adp_get($admin, '?tab=money')->assertOk());

    // The widgets are lazy: the page reads none of their figures, only the session, the user and the navigation badges.
    expect(count($large))->toBe(count($small), implode("\n", $large))
        ->and(count($largeMoney))->toBe(count($smallMoney))
        ->and(count($smallMoney))->toBe(count($small))
        ->and(count($small))->toBeLessThanOrEqual(8, implode("\n", $small));

    foreach ([...$small, ...$smallMoney] as $sql) {
        expect($sql)->not->toMatch('/learner_daily_stats|training_reviews|payments|product_events|organizations/');
    }
});
