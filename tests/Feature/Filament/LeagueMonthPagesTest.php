<?php

declare(strict_types=1);

use App\Domain\Administration\Services\PendingLeagueMonthsBadge;
use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Operations\Support\PureCache;
use App\Filament\Pages\LeagueMonthReview;
use App\Filament\Pages\LeagueMonths;
use App\Filament\Resources\Support\AdminErrors;
use App\Models\AuditLog;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

use Tests\Support\QueryLog;

/*
 * The administrator's pages of the monthly rating (docs/13 §7.6): the list of months with the badge of the one waiting for the
 * review, the page of a month with the prizes, the candidates with their signals and every decision (confirm, disqualify,
 * reinstate, prize delivered, prizes) through MonthlyRatingReviewService; a refusal is a notification; only an admin gets in;
 * the number of statements does not grow with the number of rows.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    config([
        'leagues.monthly.places' => 3,
        'leagues.monthly.prizes' => [
            1 => ['ru' => 'Telegram Premium на 3 месяца', 'uz' => 'Telegram Premium 3 oyga'],
            2 => ['ru' => 'Книга', 'uz' => 'Kitob'],
        ],
    ]);
    $this->admin = lmpUser('admin');
    $this->actingAs($this->admin);
    PendingLeagueMonthsBadge::forget();
});

function lmpUser(string $role): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole($role);

    return $user;
}

/**
 * A pending October with `$count` candidates ranked 1..count.
 *
 * @return array{month: LeagueMonth, candidates: list<LeagueMonthCandidate>}
 */
function lmpMonth(int $count = 4, string $starts = '2026-10-01'): array
{
    $month = LeagueMonth::factory()->startingOn($starts)->pendingReview()->create(['participants_count' => 120]);
    $candidates = [];
    foreach (range(1, $count) as $rank) {
        $learner = Learner::factory()->create(['first_name' => "Learner{$rank}", 'username' => "user_{$rank}_name", 'telegram_user_id' => 7000 + $rank]);
        $candidates[] = LeagueMonthCandidate::factory()->create([
            'league_month_id' => $month->id,
            'learner_id' => $learner->id,
            'rank' => $rank,
            'points' => 1000 - $rank * 10,
            'active_days' => 20,
            'signals' => [
                'accuracy' => 0.873, 'avg_ms_per_review' => 1200, 'capped_days' => 6, 'max_day_points' => 300, 'spike_ratio' => 3.4,
                'account_age_days' => 12, 'overall_rank' => $rank + 1, 'flags' => ['too_fast', 'young_account', 'capped_often'],
            ],
        ]);
    }

    return ['month' => $month, 'candidates' => $candidates];
}

function lmpReview(LeagueMonth $month)
{
    return livewire(LeagueMonthReview::class, ['month' => $month]);
}

// --- access --------------------------------------------------------------------------------

it('opens for an active admin and for nobody else', function (): void {
    ['month' => $month] = lmpMonth();

    foreach (['/admin/league-months', "/admin/league-months/{$month->id}"] as $url) {
        $this->actingAs($this->admin)
            ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id])
            ->get($url)->assertOk();

        $teacher = lmpUser('teacher');
        $this->actingAs($teacher)
            ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $teacher->id])
            ->get($url)->assertForbidden();

        $inactive = lmpUser('admin');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)
            ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $inactive->id])
            ->get($url)->assertForbidden();
    }
});

it('refuses a teacher at mount and at every decision', function (): void {
    ['month' => $month, 'candidates' => $c] = lmpMonth();
    $this->actingAs(lmpUser('teacher'));

    livewire(LeagueMonthReview::class, ['month' => $month])->assertForbidden();
    expect(LeagueMonths::canAccess())->toBeFalse();

    // Even a component that somehow mounted (an admin whose rights were taken away meanwhile): every decision asks the Gate.
    $this->actingAs($this->admin);
    $page = lmpReview($month)->instance();
    $this->admin->removeRole('admin');
    $this->actingAs(User::query()->findOrFail($this->admin->id));
    $decide = new ReflectionMethod($page, 'decide');
    $service = fn () => app(MonthlyRatingReviewService::class)->disqualify($month, $c[0]->id, 'x', $this->admin);

    expect(fn () => $decide->invoke($page, $service, 'disqualified'))->toThrow(AuthorizationException::class)
        ->and($c[0]->fresh()?->isDisqualified())->toBeFalse();
});

// --- list ----------------------------------------------------------------------------------

it('lists the months newest first with status, counts, who confirmed and a link to each', function (): void {
    ['month' => $october] = lmpMonth(3);
    $confirmer = User::factory()->create(['name' => 'Анна Админова']);
    $september = LeagueMonth::factory()->startingOn('2026-09-01')->confirmed()->create(['participants_count' => 80, 'confirmed_by_user_id' => $confirmer->id]);
    LeagueMonthCandidate::factory()->count(2)->create(['league_month_id' => $september->id]);

    livewire(LeagueMonths::class)
        ->assertOk()
        ->assertSeeInOrder(['октябрь 2026', 'Ждёт проверки', '120', '3', 'сентябрь 2026', 'Подтверждён', '80', '2', 'Анна Админова'])
        ->assertSee(LeagueMonthReview::getUrl(['month' => $october->id]));
});

it('says so when there are no months yet', function (): void {
    livewire(LeagueMonths::class)->assertOk()->assertSee('Месяцев пока нет');
});

// --- menu badge ----------------------------------------------------------------------------

it('marks the menu item with the number of months waiting for the review', function (): void {
    expect(LeagueMonths::getNavigationBadge())->toBeNull();

    PendingLeagueMonthsBadge::forget();
    lmpMonth(1);
    LeagueMonth::factory()->startingOn('2026-09-01')->confirmed()->create();

    expect(LeagueMonths::getNavigationBadge())->toBe('1')
        ->and(LeagueMonths::getNavigationBadgeTooltip())->toBe('Ждёт проверки');

    $this->actingAs(lmpUser('teacher'));
    expect(LeagueMonths::getNavigationBadge())->toBeNull();
});

// Redis hands a cached number back as a STRING; the array store of the tests keeps the int, so the production 500 (a
// TypeError under strict_types on every page of the panel) shows only when the string is put in by hand.
it('returns an int and shows the badge when the cached number comes back as a string (Redis)', function (): void {
    PureCache::store()->put(PendingLeagueMonthsBadge::KEY, '2', 60);

    expect(PendingLeagueMonthsBadge::count())->toBe(2)
        ->and(LeagueMonths::getNavigationBadge())->toBe('2');
});

it('drops the cached number when the month is confirmed', function (): void {
    ['month' => $month] = lmpMonth(2);
    expect(PendingLeagueMonthsBadge::count())->toBe(1);

    app(MonthlyRatingReviewService::class)->confirm($month, $this->admin);

    expect(PendingLeagueMonthsBadge::count())->toBe(0);
});

it('drops the cached number when the close makes a month pending', function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent', 'leagues.monthly.close_delay_minutes' => 180]);
    LeagueMonth::factory()->startingOn('2026-10-01')->create(['status' => 'open']);
    expect(PendingLeagueMonthsBadge::count())->toBe(0);

    app(MonthlyRatingService::class)->closeDue(CarbonImmutable::parse('2026-11-02 00:00:00', 'UTC'));

    expect(PendingLeagueMonthsBadge::count())->toBe(1);
});

// --- the page of a month -------------------------------------------------------------------

it('shows the prizes and the candidates with places, signals, flags and the way to reach a winner', function (): void {
    ['month' => $month, 'candidates' => $c] = lmpMonth(4);
    $c[1]->forceFill(['status' => LeagueMonthCandidateStatus::Disqualified->value, 'disqualify_reason' => 'Скрипт на карточках', 'disqualified_at' => now()])->save();

    lmpReview($month)
        ->assertOk()
        ->assertSee('Рейтинг: октябрь 2026')
        ->assertSee('Telegram Premium на 3 месяца')
        ->assertSee('Telegram Premium 3 oyga')
        ->assertSee('Learner1')
        ->assertSee('Дисквалифицирован')
        ->assertSee('Скрипт на карточках')
        // The signals of a row: the coloured flags with captions and the facts.
        ->assertSee('Слишком быстро')
        ->assertSee('меньше 1,5 с на оценку')
        ->assertSee('Новый аккаунт')
        ->assertSee('Часто упирался в потолок')
        ->assertSee('Точность: 87,3 %')
        ->assertSee('Среднее время на ответ: 1,2 с')
        ->assertSee('Дней с потолком: 6')
        ->assertSee('Максимум за день: 300')
        ->assertSee('Всплеск: ×3,4')
        ->assertSee('Возраст аккаунта: 12 дн.')
        ->assertSee('Общее место: 2')
        // Contact data of the places only (the 4th is the one who would get no place: Learner4 is 3rd after the disqualification).
        ->assertSee('https://t.me/user_1_name')
        ->assertSee('tg://user?id=7001', false)
        ->assertDontSee('tg://user?id=7002', false)
        ->assertSee('Дисквалифицировать')
        ->assertSee('Вернуть')
        ->assertSee('Подтвердить итоги');
});

it('hides the decisions the state does not allow: nothing to confirm in a confirmed month, a prize button only for a winner without one', function (): void {
    $month = LeagueMonth::factory()->startingOn('2026-10-01')->confirmed()->create();
    $winner = LeagueMonthCandidate::factory()->winner(1)->create(['league_month_id' => $month->id, 'learner_id' => Learner::factory()->create(['first_name' => 'Pobeditel'])->id]);
    $done = LeagueMonthCandidate::factory()->winner(2)->create(['league_month_id' => $month->id, 'learner_id' => Learner::factory()->create()->id, 'prize_delivered_at' => now()]);

    lmpReview($month)
        ->assertOk()
        ->assertDontSee('Подтвердить итоги')
        ->assertDontSee('Изменить призы')
        ->assertDontSee('Дисквалифицировать')
        ->assertSee('призы больше не меняются')
        ->assertSee('Приз не вручён')
        ->assertSee('Приз вручён ')
        ->assertActionVisible(TestAction::make('prizeDelivered')->arguments(['candidate' => $winner->id]));
});

it('disqualifies with a required reason, the next candidate moves up, and takes it back', function (): void {
    ['month' => $month, 'candidates' => $c] = lmpMonth(4);

    lmpReview($month)
        ->callAction(TestAction::make('disqualify')->arguments(['candidate' => $c[0]->id, 'name' => 'Learner1']), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($c[0]->fresh()?->isDisqualified())->toBeFalse();

    lmpReview($month)
        ->callAction(TestAction::make('disqualify')->arguments(['candidate' => $c[0]->id, 'name' => 'Learner1']), ['reason' => 'Накрутка'])
        ->assertNotified('Кандидат дисквалифицирован');

    $fresh = $c[0]->fresh();
    expect($fresh?->isDisqualified())->toBeTrue()
        ->and($fresh?->disqualify_reason)->toBe('Накрутка')
        ->and($fresh?->disqualified_by_user_id)->toBe($this->admin->id)
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_DISQUALIFIED)->count())->toBe(1);

    lmpReview($month)
        ->callAction(TestAction::make('reinstate')->arguments(['candidate' => $c[0]->id]))
        ->assertNotified('Кандидат возвращён');
    expect($c[0]->fresh()?->isDisqualified())->toBeFalse();
});

it('confirms the month: the modal names the final places and prizes, the event goes out, the page turns to a confirmed month', function (): void {
    Event::fake([LeagueMonthConfirmed::class]);
    ['month' => $month, 'candidates' => $c] = lmpMonth(4);
    $c[0]->forceFill(['status' => LeagueMonthCandidateStatus::Disqualified->value, 'disqualify_reason' => 'x', 'disqualified_at' => now()])->save();

    $page = lmpReview($month);
    $page->mountAction('confirm')
        ->assertMountedActionModalSee(['1 место — Learner2, 980 баллов', 'Telegram Premium на 3 месяца / Telegram Premium 3 oyga', '3 место — Learner4', 'без приза', 'Это необратимо', 'бот отправит поздравление']);
    expect($page->getMountedActionModalHtml())->not->toContain('Learner1');
    $page->callMountedAction()->assertNotified('Итоги подтверждены');

    Event::assertDispatched(LeagueMonthConfirmed::class, fn (LeagueMonthConfirmed $e): bool => array_column($e->winners, 'learner_id') === [$c[1]->learner_id, $c[2]->learner_id, $c[3]->learner_id]);
    expect($month->fresh()?->status)->toBe('confirmed')
        ->and($month->fresh()?->confirmed_by_user_id)->toBe($this->admin->id);

    $page->assertDontSee('Подтвердить итоги');
});

it('marks a prize as delivered once, then shows the date', function (): void {
    ['month' => $month, 'candidates' => $c] = lmpMonth(2);
    app(MonthlyRatingReviewService::class)->confirm($month, $this->admin);

    $page = lmpReview($month);
    $page->callAction(TestAction::make('prizeDelivered')->arguments(['candidate' => $c[0]->id, 'name' => 'Learner1']))
        ->assertNotified('Приз отмечен как врученный');
    expect($c[0]->fresh()?->prize_delivered_at)->not->toBeNull();

    $page->callAction(TestAction::make('prizeDelivered')->arguments(['candidate' => $c[0]->id]))
        ->assertNotified('Приз уже отмечен как врученный.');
});

it('shows a refusal of the service as a notification and does not fail', function (): void {
    ['month' => $month, 'candidates' => $c] = lmpMonth(2);
    $page = lmpReview($month);

    // The month is confirmed by somebody else meanwhile.
    app(MonthlyRatingReviewService::class)->confirm($month, $this->admin);

    $page->callAction(TestAction::make('disqualify')->arguments(['candidate' => $c[0]->id]), ['reason' => 'Поздно'])
        ->assertNotified(__('admin.league_month.errors.month_not_pending'))
        ->assertOk();
    $page->callAction(TestAction::make('prizeDelivered')->arguments(['candidate' => 0]))->assertNotified(__('admin.league_month.errors.candidate_not_found'));

    expect($c[0]->fresh()?->isDisqualified())->toBeFalse();
});

it('words every error code of the review in Russian', function (): void {
    foreach (['month_not_pending', 'month_not_confirmed', 'prizes_locked', 'prizes_invalid', 'reason_invalid', 'candidate_not_found', 'candidate_not_winner', 'candidate_already_disqualified', 'candidate_not_disqualified', 'prize_already_delivered'] as $code) {
        $text = AdminErrors::text('league_month', $code, ['places' => 3, 'max_prize' => 200, 'max_reason' => 500]);

        expect($text)->toMatch('/[а-яё]/ui')->not->toContain('admin.')->not->toMatch('/:[a-z_]+/');
    }

    // The other refusals reach the notification through the page: not a winner, not disqualified, twice disqualified.
    ['month' => $month, 'candidates' => $c] = lmpMonth(2);
    $page = lmpReview($month);
    $page->callAction(TestAction::make('reinstate')->arguments(['candidate' => $c[0]->id]))->assertNotified(__('admin.league_month.errors.candidate_not_disqualified'));
    $page->callAction(TestAction::make('prizeDelivered')->arguments(['candidate' => $c[0]->id]))->assertNotified(__('admin.league_month.errors.month_not_confirmed'));
    $page->callAction(TestAction::make('disqualify')->arguments(['candidate' => $c[0]->id]), ['reason' => 'a']);
    $page->callAction(TestAction::make('disqualify')->arguments(['candidate' => $c[0]->id]), ['reason' => 'b'])->assertNotified(__('admin.league_month.errors.candidate_already_disqualified'));
});

it('edits the prizes of a month: its own list, none at all, and back to the defaults', function (): void {
    ['month' => $month] = lmpMonth(2);

    // The form starts from the prizes in force (here: the defaults of the config).
    lmpReview($month)
        ->mountAction('editPrizes')
        ->assertSchemaStateSet(['ru_1' => 'Telegram Premium на 3 месяца', 'uz_2' => 'Kitob', 'ru_3' => '']);

    lmpReview($month)
        ->callAction('editPrizes', ['ru_1' => 'Книга', 'uz_1' => 'Kitob', 'ru_2' => '', 'uz_2' => '', 'ru_3' => 'Сертификат', 'uz_3' => ''])
        ->assertNotified('Призы сохранены');
    expect($month->fresh()?->prizes)->toBe([['place' => 1, 'ru' => 'Книга', 'uz' => 'Kitob'], ['place' => 3, 'ru' => 'Сертификат', 'uz' => '']]);

    lmpReview($month)->callAction('clearPrizes')->assertNotified('Призы сохранены')->assertSee('Призы не заданы');
    expect($month->fresh()?->prizes)->toBe([]);

    lmpReview($month)->callAction('defaultPrizes')->assertNotified('Призы сохранены')->assertSee('настроек по умолчанию');
    expect($month->fresh()?->prizes)->toBeNull();

    // A title over the limit is refused by the form, never reaches the service.
    lmpReview($month)->callAction('editPrizes', ['ru_1' => str_repeat('я', 201)])->assertHasActionErrors(['ru_1' => 'max']);
});

it('shows the points of a candidate day by day with the cap applied, and only for the candidates of the page', function (): void {
    config(['leagues.monthly.daily_cap' => 300]);
    ['month' => $month, 'candidates' => $c] = lmpMonth(2);
    LearnerDailyStats::factory()->create(['learner_id' => $c[0]->learner_id, 'date' => '2026-10-07', 'reviews_count' => 400, 'correct_count' => 400, 'new_words_count' => 0]);
    LearnerDailyStats::factory()->create(['learner_id' => $c[0]->learner_id, 'date' => '2026-10-08', 'reviews_count' => 10, 'correct_count' => 8, 'new_words_count' => 2]);
    $stranger = Learner::factory()->create();

    lmpReview($month)
        ->mountAction(TestAction::make('days')->arguments(['learner' => $c[0]->learner_id, 'name' => 'Learner1']))
        ->assertMountedActionModalSee(['Баллы по дням: Learner1', '07.10.2026', '800', '300', '08.10.2026', '22']);

    lmpReview($month)
        ->mountAction(TestAction::make('days')->arguments(['learner' => $stranger->id, 'name' => 'X']))
        ->assertNotFound();
});

// --- query budget --------------------------------------------------------------------------

it('keeps the list of months at a constant number of statements however many months there are', function (): void {
    $measure = function (int $months): int {
        foreach (range(1, $months) as $i) {
            $starts = CarbonImmutable::parse('2020-01-01')->addMonths(LeagueMonth::query()->count());
            $month = LeagueMonth::factory()->startingOn($starts->toDateString())->confirmed()->create(['confirmed_by_user_id' => User::factory()->create()->id]);
            LeagueMonthCandidate::factory()->count(2)->create(['league_month_id' => $month->id]);
        }
        app()->forgetScopedInstances();
        Livewire::test(LeagueMonths::class);

        return count(QueryLog::during(fn () => Livewire::test(LeagueMonths::class)->assertOk()));
    };

    $small = $measure(3);
    $large = $measure(20);

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(10);
});

it('keeps the page of a month at a constant number of statements however many candidates there are', function (): void {
    ['month' => $month] = lmpMonth(1);
    $add = function (int $upTo) use ($month): void {
        for ($rank = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->count() + 1; $rank <= $upTo; $rank++) {
            LeagueMonthCandidate::factory()->create([
                'league_month_id' => $month->id,
                'learner_id' => Learner::factory()->create()->id,
                'rank' => $rank,
                'signals' => ['accuracy' => 0.9, 'avg_ms_per_review' => 1000, 'flags' => ['too_fast', 'spiky']],
            ]);
        }
    };
    $measure = function () use ($month): int {
        app()->forgetScopedInstances();
        Livewire::test(LeagueMonthReview::class, ['month' => $month]);

        return count(QueryLog::during(fn () => Livewire::test(LeagueMonthReview::class, ['month' => $month])->assertOk()));
    };

    $add(10);
    $small = $measure();
    $add(50);
    $large = $measure();

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(8);
});
