<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Org\Pages\Reports;
use App\Filament\Org\Resources\GroupResource\Pages\ListGroups;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\DailyStatsRelationManager;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\TelegramGroupResource\Pages\ListTelegramGroups;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Models\LeagueGroup;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Dates in both panels read «28.10.2026» and «28.10.2026 09:27», not Filament's «окт 28, 2026 09:27:19»: one default for
 * tables, infolists and non-native date pickers, set in FilamentDefaultsProvider. A column with its own format keeps it.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    $this->travelTo('2026-10-28 09:27:19');
});

function dd_admin(): User
{
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');
    test()->actingAs($admin);

    return $admin;
}

function dd_org_owner(Organization $organization): User
{
    $owner = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    Filament::setCurrentPanel(Filament::getPanel('org'));
    Filament::bootCurrentPanel();
    test()->actingAs($owner, 'org');
    Session::put('2fa.org.passed_at', now()->timestamp);
    Session::put('2fa.org.user_id', $owner->id);
    Filament::setTenant($organization, isQuiet: true);

    return $owner;
}

it('shows a datetime column of /admin as dd.mm.yyyy hh:mm', function (): void {
    dd_admin();
    $group = TelegramGroup::factory()->create(['created_at' => '2026-10-28 09:27:19']);

    Livewire::test(ListTelegramGroups::class)
        ->assertTableColumnFormattedStateSet('created_at', '28.10.2026 09:27', $group);

    Livewire::test(ListPayments::class)->assertSuccessful();
});

it('shows a date column of /admin as dd.mm.yyyy', function (): void {
    dd_admin();
    $learner = Learner::factory()->create();
    $stats = LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => '2026-10-27']);

    Livewire::test(DailyStatsRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class])
        ->assertTableColumnFormattedStateSet('date', '27.10.2026', $stats);
});

it('shows the dates of a /admin infolist as dd.mm.yyyy hh:mm', function (): void {
    dd_admin();
    $payment = Payment::factory()->create([
        'occurred_at' => '2026-10-28 09:27:19',
        'period_starts_at' => '2026-10-28 09:27:19',
        'period_ends_at' => '2026-11-27 09:27:19',
    ]);

    Livewire::test(ViewPayment::class, ['record' => $payment->getRouteKey()])
        ->assertSee('28.10.2026 09:27')
        ->assertSee('27.11.2026 09:27')
        ->assertDontSee('09:27:19')
        ->assertDontSee('окт 28, 2026');
});

it('applies the same default in /org: tables, infolists and non-native date pickers', function (): void {
    $organization = Organization::factory()->create(['slug' => 'center-a', 'locale' => 'ru', 'timezone' => 'UTC']);
    Subscription::factory()->forOrganization($organization)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    dd_org_owner($organization);

    $table = Livewire::test(ListGroups::class)->instance()->getTable();

    expect($table->getDefaultDateDisplayFormat())->toBe('d.m.Y')
        ->and($table->getDefaultDateTimeDisplayFormat())->toBe('d.m.Y H:i');

    // The picker of a custom report period is non-native: it shows the date in the display format, written for dayjs.
    Livewire::test(Reports::class)
        ->set('data.period', 'custom')
        ->assertSeeHtml('DD.MM.YYYY');
});

it('keeps an explicit column format in /org', function (): void {
    $organization = Organization::factory()->create(['slug' => 'center-a', 'locale' => 'ru', 'timezone' => 'UTC']);
    Subscription::factory()->forOrganization($organization)->create(['current_period_ends_at' => null, 'tier' => 'tutor', 'seats_limit' => 30]);
    dd_org_owner($organization);
    $group = TelegramGroup::factory()->create(['organization_id' => $organization->id, 'status' => 'active', 'created_at' => '2026-10-28 09:27:19']);

    // GroupResource asks `->date('d.m.Y')` for «since»: the global datetime default does not reach it.
    Livewire::test(ListGroups::class)->assertTableColumnFormattedStateSet('created_at', '28.10.2026', $group);
});

it('sets the display format of date and datetime pickers once', function (): void {
    expect(DatePicker::make('day')->getDisplayFormat())->toBe('d.m.Y')
        ->and(DateTimePicker::make('moment')->seconds(false)->getDisplayFormat())->toBe('d.m.Y H:i')
        ->and(DateTimePicker::make('moment')->getDisplayFormat())->toBe('d.m.Y H:i:s')
        ->and(DateTimePicker::make('moment')->displayFormat('Y')->getDisplayFormat())->toBe('Y')
        ->and(Schema::make()->getDefaultDateDisplayFormat())->toBe('d.m.Y')
        ->and(Schema::make()->getDefaultDateTimeDisplayFormat())->toBe('d.m.Y H:i');
});

// --- dates inside a sentence: the same format as the columns -----------------------------------------------

it('writes the end of the plan in the learner card as dd.mm.yyyy hh:mm, not as an ISO date', function (): void {
    dd_admin();
    $learner = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'subscriber_type' => 'learner', 'plan' => 'pro', 'status' => 'active', 'provider' => 'manual', 'current_period_ends_at' => '2026-11-27 12:00:00']);

    Livewire::test(ViewLearner::class, ['record' => $learner->getKey()])
        ->assertSee('Pro, до 27.11.2026 12:00')
        ->assertDontSee('2026-11-27')
        ->assertDontSee('12:00:00');
});

it('shows the end of the plan in the same time zone as the datetime columns of the panel', function (): void {
    dd_admin();
    FilamentTimezone::set('Asia/Tashkent');
    $learner = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'subscriber_type' => 'learner', 'plan' => 'pro', 'status' => 'active', 'provider' => 'manual', 'current_period_ends_at' => '2026-11-27 12:00:00']);

    Livewire::test(ViewLearner::class, ['record' => $learner->getKey()])
        ->assertSee('Pro, до 27.11.2026 17:00');
});

it('names the paid-until date of "Выдать Pro" as dd.mm.yyyy hh:mm in the time zone of its date picker', function (): void {
    dd_admin();
    config(['learners.default_timezone' => 'Asia/Tashkent']);
    $learner = Learner::factory()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'subscriber_type' => 'learner', 'plan' => 'pro', 'status' => 'active', 'provider' => 'payme', 'current_period_ends_at' => '2026-11-27 12:00:00']);

    Livewire::test(ListSubscriptions::class)
        ->mountAction('grantPro')
        ->setActionData(['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => false, 'until' => '2026-11-20 10:00:00'])
        ->assertMountedActionModalSee('Часовой пояс: Asia/Tashkent.')
        ->assertMountedActionModalSee('ученик уже оплатил до 27.11.2026 17:00;')
        ->assertMountedActionModalDontSee('2026-11-27');
});

it('warns in "Выдать Pro" only when the typed moment is really before the paid one, in the zone of the picker whichever it is', function (string $zone, string $typed, bool $warns): void {
    dd_admin();
    config(['learners.default_timezone' => $zone]);
    $learner = Learner::factory()->create();
    // 12:00 UTC: 17:00 in Tashkent (UTC+5), 07:00 in New York (UTC-5 in November), 12:00 in UTC.
    Subscription::factory()->create(['subscriber_id' => $learner->id, 'subscriber_type' => 'learner', 'plan' => 'pro', 'status' => 'active', 'provider' => 'payme', 'current_period_ends_at' => '2026-11-27 12:00:00']);

    // setActionData is what the browser sends: the clock time of the picker, in the zone of the picker.
    $dialog = Livewire::test(ListSubscriptions::class)
        ->mountAction('grantPro')
        ->setActionData(['subscriber_type' => 'learner', 'learner_id' => $learner->id, 'perpetual' => false, 'until' => $typed]);

    $warns
        ? $dialog->assertMountedActionModalSee('сократит оплаченный период')
        : $dialog->assertMountedActionModalDontSee('сократит оплаченный период');
})->with([
    'Tashkent, a minute before the paid moment (16:59 = 11:59 UTC)' => ['Asia/Tashkent', '2026-11-27 16:59:00', true],
    'Tashkent, the very paid moment (17:00 = 12:00 UTC)' => ['Asia/Tashkent', '2026-11-27 17:00:00', false],
    'Tashkent, a minute after (17:01 = 12:01 UTC)' => ['Asia/Tashkent', '2026-11-27 17:01:00', false],
    'Tashkent, the clock of UTC typed by mistake (12:00 = 07:00 UTC)' => ['Asia/Tashkent', '2026-11-27 12:00:00', true],
    'New York, a minute before (06:59 = 11:59 UTC)' => ['America/New_York', '2026-11-27 06:59:00', true],
    'New York, the very paid moment (07:00 = 12:00 UTC)' => ['America/New_York', '2026-11-27 07:00:00', false],
    'New York, a minute after (07:01 = 12:01 UTC)' => ['America/New_York', '2026-11-27 07:01:00', false],
    'New York, the clock of UTC typed by mistake (12:00 = 17:00 UTC)' => ['America/New_York', '2026-11-27 12:00:00', false],
    'UTC, a minute before' => ['UTC', '2026-11-27 11:59:00', true],
    'UTC, the very paid moment' => ['UTC', '2026-11-27 12:00:00', false],
]);

it('writes the week of the league widget as dd.mm.yyyy', function (): void {
    dd_admin();
    config(['leagues.timezone' => 'Asia/Tashkent']);
    $week = LeagueWeek::factory()->startingOn('2026-10-26')->create();
    LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'members_count' => 5]);

    Livewire::test(LeagueGroupsTableWidget::class)
        ->assertSee('Лиги — неделя 26.10.2026 – 01.11.2026')
        ->assertDontSee('2026-10-26');
});
