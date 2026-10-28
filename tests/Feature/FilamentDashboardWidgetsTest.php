<?php

declare(strict_types=1);

use App\Filament\Widgets\ActivityChartWidget;
use App\Filament\Widgets\ExamsLast30DaysWidget;
use App\Filament\Widgets\HardestWordsTableWidget;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Filament\Widgets\TopLearnersTableWidget;
use App\Filament\Widgets\TotalLearnersWidget;
use App\Models\LeagueGroup;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\TelegramGroup;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\Word;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(RolePermissionSeeder::class)->run();

    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    Auth::login($admin);
    Session::put('2fa.web.passed_at', now()->timestamp);
    Session::put('2fa.web.user_id', $admin->id);
});

it('renders all dashboard widgets without livewire errors', function (): void {
    $learner = Learner::factory()->create();
    $word = Word::factory()->create(['word' => 'difficult']);

    TrainingReview::factory()
        ->count(5)
        ->create([
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'quality' => 2,
            'created_at' => now(),
        ]);

    Livewire::test(TotalLearnersWidget::class)->assertOk();
    Livewire::test(ExamsLast30DaysWidget::class)->assertOk();
    Livewire::test(ActivityChartWidget::class)->assertOk();
    Livewire::test(TopLearnersTableWidget::class)->assertCanSeeTableRecords([$learner]);
    Livewire::test(HardestWordsTableWidget::class)->assertCanSeeTableRecords([$word]);
});

it('counts the total and the recently active learners of the platform for an admin', function (): void {
    Learner::factory()->create(['last_seen_at' => now()->subDay()]);
    Learner::factory()->create(['last_seen_at' => now()->subDays(30)]);
    Learner::factory()->create(['last_seen_at' => null]);

    Livewire::test(TotalLearnersWidget::class)
        ->assertSee('Всего учеников')
        ->assertSee('3')
        ->assertSee('Активны за 7 дней');
});

it('scopes the widgets of a teacher to group sessions of their own groups', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');
    $ownGroup = TelegramGroup::factory()->create(['title' => 'Own Class']);
    $otherGroup = TelegramGroup::factory()->create();
    $teacher->telegramGroups()->attach($ownGroup->id);

    $diligent = Learner::factory()->memberOf($ownGroup)->create(['first_name' => 'Diligent']);
    $privateOnly = Learner::factory()->memberOf($ownGroup)->create(['first_name' => 'PrivateOnly']);
    $foreign = Learner::factory()->memberOf($otherGroup)->create(['first_name' => 'Foreign']);

    $ownSession = TrainingSession::factory()->create(['telegram_group_id' => $ownGroup->id]);
    $otherSession = TrainingSession::factory()->create(['telegram_group_id' => $otherGroup->id]);
    $makeReviews = fn (TrainingSession $session, Learner $learner, Word $word, int $count) => TrainingReview::factory()
        ->count($count)
        ->create([
            'training_session_id' => $session->id,
            'learner_id' => $learner->id,
            'word_id' => $word->id,
            'quality' => 1,
            'created_at' => now(),
        ]);

    $groupWord = Word::factory()->create(['word' => 'groupword']);
    $personalWord = Word::factory()->create(['word' => 'personalword']);
    $foreignWord = Word::factory()->create(['word' => 'foreignword']);

    $makeReviews($ownSession, $diligent, $groupWord, 6);
    $makeReviews(TrainingSession::factory()->personal($diligent)->create(), $diligent, $personalWord, 9);
    $makeReviews(TrainingSession::factory()->personal($privateOnly)->create(), $privateOnly, $personalWord, 9);
    $makeReviews($otherSession, $foreign, $foreignWord, 9);

    Auth::login($teacher);

    Livewire::test(TopLearnersTableWidget::class)
        ->assertCanSeeTableRecords([$diligent])
        ->assertCanNotSeeTableRecords([$privateOnly, $foreign])
        ->assertSee('Own Class')
        ->assertSee('6');

    Livewire::test(HardestWordsTableWidget::class)
        ->assertCanSeeTableRecords([$groupWord])
        ->assertCanNotSeeTableRecords([$personalWord, $foreignWord]);

    Livewire::test(TotalLearnersWidget::class)->assertOk()->assertSee('2');
});

it('lists the groups of the current league week with members and capacity for an admin', function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent']);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC'));
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $gold = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 2, 'members_count' => 30, 'capacity' => 30]);
    $bronze = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0, 'members_count' => 7]);
    $lastWeek = LeagueGroup::factory()->create(['league_week_id' => LeagueWeek::factory()->startingOn('2026-10-12')->closed()->create()->id]);

    Livewire::test(LeagueGroupsTableWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$gold, $bronze])
        ->assertCanNotSeeTableRecords([$lastWeek])
        ->assertSee('Лиги — неделя 2026-10-19 – 2026-10-25 (идёт): групп — 2, участников — 37')
        ->assertSee('Золото (2)')
        ->assertSee('30 / 30')
        ->assertSee('7 / 30');

    expect(LeagueGroupsTableWidget::canView())->toBeTrue();
});

it('shows an empty league widget before the first placement of the week', function (): void {
    Livewire::test(LeagueGroupsTableWidget::class)
        ->assertOk()
        ->assertSee('Лиги — текущая неделя: групп пока нет');
});
