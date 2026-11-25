<?php

declare(strict_types=1);

/*
 * /admin for the word points v2 (docs/32 §8.5, task S15): the rules read only on «Рейтинг месяца»; the "Очки по дням" tab of a
 * learner and the journal of a day with «Аннулировать день» / «Аннулировать начисление» / «Восстановить» through
 * ScoreCorrectionService (a required reason, admin only, the refusals worded); the review of a v2 month by category with shared
 * places, 3 + 3 prizes and the explicit confirmation of a tie beyond the reserve; the category in the league widget. Every new or
 * changed list keeps a constant number of statements (docs/20 §1.13).
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\LeagueMonthCandidateStatus;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Events\LeagueMonthConfirmed;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Filament\Pages\LeagueMonthReview;
use App\Filament\Pages\LeagueMonths;
use App\Filament\Pages\LearnerScoreDayCredits;
use App\Filament\Resources\LearnerResource\Pages\ViewLearner;
use App\Filament\Resources\LearnerResource\RelationManagers\ScoreDaysRelationManager;
use App\Filament\Resources\Support\AdminErrors;
use App\Filament\Widgets\LeagueGroupsTableWidget;
use App\Models\AuditLog;
use App\Models\LeagueGroup;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Livewire\livewire;

use Tests\Support\FilamentQueryBudget;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->seed(RolePermissionSeeder::class);
    app()->setLocale('ru');
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.categories', ['free' => ['places' => 3, 'tie_reserve' => 1], 'pro' => ['places' => 3, 'tie_reserve' => 1]]);
    $this->admin = sapUser('admin');
    $this->actingAs($this->admin);
});

function sapUser(string $role): User
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

function sapAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

/** A repetition credit (3 points; long = 5 with an interval ≥ 7) of a fresh confirmed lexeme, through the scoring core. */
function sapRepeat(Learner $learner, CarbonImmutable $now, int $interval = 3): WordScoreOutcome
{
    $lexeme = Lexeme::factory()->create()->id;
    LearnerLexeme::query()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-01-10']);
    $before = new CardStateBefore(RepetitionState::Review, $interval, $now->subDays($interval)->subHour());

    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learner->id, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, $before, Plan::Free, $now),
    ));
}

/** `$count` credits of the learner on 03.02.2027 (Free: 20 repetitions count, the rest are journaled with 0). */
function sapDay(Learner $learner, int $count): void
{
    $have = ScoreCredit::query()->where('learner_id', $learner->id)->count();
    for ($i = $have; $i < $count; $i++) {
        sapRepeat($learner, sapAt('2027-02-03 10:00')->addMinutes($i));
    }
}

function sapScoreDay(Learner $learner, string $day = '2027-02-03'): LearnerScoreDay
{
    return LearnerScoreDay::query()->where('learner_id', $learner->id)->whereDate('rating_day', $day)->sole();
}

function sapJournal(Learner $learner, string $day = '2027-02-03')
{
    return livewire(LearnerScoreDayCredits::class, ['learner' => $learner->id, 'day' => $day]);
}

function sapTab(Learner $learner)
{
    return livewire(ScoreDaysRelationManager::class, ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);
}

// --- rules ---------------------------------------------------------------------------------

it('shows the word-points rules read only above the months: mode, start, versions of the current periods, limits, game, retention', function (): void {
    $this->travelTo(sapAt('2027-02-10 12:00'));
    LeagueWeek::factory()->startingOn('2027-02-08')->create(['rules_version' => 2]);

    livewire(LeagueMonths::class)
        ->assertOk()
        ->assertSee('Правила очков за слова')
        ->assertSee('Включено (live)')
        ->assertSee('01.02.2027')
        ->assertSee('с 08.02.2027, правила v2')
        // No row of February yet: the version it would get if it were created now.
        ->assertSee('с 01.02.2027, строки ещё нет — получит правила v2')
        ->assertSee('новое слово — 2, повтор в срок — 3, длинный повтор (интервал от 7 дн.) — 5; неделя — 5 лучших дней.')
        ->assertSeeInOrder(['Free', '20', '20', '100', 'Pro', '30', '30', '150'])
        ->assertSee('хранится 180 дн.');

    config()->set('leagues.scoring.mode', 'off');
    config()->set('leagues.scoring.versions.2.starts_on', null);
    config()->set('leagues.scoring.game_enabled', false);
    livewire(LeagueMonths::class)->assertSee('Выключено (старые правила)')->assertSee('не назначен')->assertSee('получит правила v1');
});

// --- the "Очки по дням" tab ----------------------------------------------------------------------

it('lists the rating days of a learner newest first with both counts, categories, the cap and the credits', function (): void {
    $learner = Learner::factory()->create();
    $old = LearnerScoreDay::factory()->on('2027-02-02')->create(['learner_id' => $learner->id, 'week_points' => 40, 'month_points' => 40, 'week_long' => 1, 'month_long' => 2]);
    $new = LearnerScoreDay::factory()->on('2027-02-03')->create([
        'learner_id' => $learner->id, 'week_category' => ScoreCategory::Free, 'month_category' => ScoreCategory::Pro,
        'week_points' => 100, 'month_points' => 120, 'week_capped_at' => now(), 'raw_points' => 140, 'revision' => 2,
    ]);
    ScoreCredit::factory()->count(2)->create(['learner_id' => $learner->id, 'rating_day' => '2027-02-03']);
    ScoreCredit::factory()->create(['learner_id' => $learner->id, 'rating_day' => '2027-02-03', 'voided_at' => now(), 'void_reason' => 'x']);
    $other = LearnerScoreDay::factory()->on('2027-02-03')->create();

    sapTab($learner)
        ->assertOk()
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->assertCanNotSeeTableRecords([$other])
        ->assertSee(['03.02.2027', 'Pro', '120', '140', '3, аннулировано 1', '1 / 2'])
        ->assertTableActionVisible('restoreDay', $new)
        ->assertTableActionHidden('restoreDay', $old)
        // The journal of the old day is empty (pruned): nothing to void.
        ->assertTableActionHidden('voidDay', $old)
        ->assertTableActionVisible('voidDay', $new);
});

it('is for an active admin only: the tab, the journal page and every correction', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 2);
    $url = "/admin/learners/{$learner->id}/score-days/2027-02-03";

    $this->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $this->admin->id])->get($url)->assertOk()->assertSee('Очки за 03.02.2027');

    $teacher = sapUser('teacher');
    $this->actingAs($teacher)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $teacher->id])->get($url)->assertForbidden();
    expect(ScoreDaysRelationManager::canViewForRecord($learner, ViewLearner::class))->toBeFalse();
    sapTab($learner)->assertForbidden();
    sapJournal($learner)->assertForbidden();

    $inactive = sapUser('admin');
    $inactive->forceFill(['is_active' => false])->save();
    $this->actingAs($inactive)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $inactive->id])->get($url)->assertForbidden();

    // An admin whose role is taken away after the page opened: the next request (the action) is refused, nothing is voided.
    $this->actingAs($this->admin);
    $page = sapJournal($learner);
    $this->admin->removeRole('admin');
    $this->actingAs(User::query()->findOrFail($this->admin->id));
    $page->call('mountAction', 'voidDay')->assertForbidden();
    expect(ScoreCredit::query()->whereNotNull('voided_at')->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('answers 404 for a malformed day, an unknown learner and a day the learner does not have', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 1);

    sapJournal($learner, '2027-02-30')->assertNotFound();
    sapJournal($learner, 'yesterday')->assertNotFound();
    sapJournal($learner, '2027-02-04')->assertNotFound();
    livewire(LearnerScoreDayCredits::class, ['learner' => 999999, 'day' => '2027-02-03'])->assertNotFound();
});

it('voids a whole day from the tab with a required reason and restores it', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 4);
    $day = sapScoreDay($learner);
    expect($day->week_points)->toBe(12);

    sapTab($learner)->callTableAction('voidDay', $day, ['reason' => ''])->assertHasTableActionErrors(['reason' => 'required']);
    expect(sapScoreDay($learner)->week_points)->toBe(12);

    sapTab($learner)->callTableAction('voidDay', $day, ['reason' => 'Общий телефон'])
        ->assertNotified('Начисления аннулированы: 4');
    expect([sapScoreDay($learner)->week_points, sapScoreDay($learner)->month_points, sapScoreDay($learner)->revision])->toBe([0, 0, 1])
        ->and(ScoreCredit::query()->where('void_reason', 'Общий телефон')->where('voided_by_user_id', $this->admin->id)->count())->toBe(4)
        ->and(AuditLog::query()->where('action', ScoreCorrectionService::ACTION_VOIDED)->where('user_id', $this->admin->id)->count())->toBe(1);

    sapTab($learner)->assertTableActionHidden('voidDay', $day)
        ->callTableAction('restoreDay', $day)
        ->assertNotified('Начисления восстановлены: 4');
    expect(sapScoreDay($learner)->week_points)->toBe(12)
        ->and(AuditLog::query()->where('action', ScoreCorrectionService::ACTION_RESTORED)->count())->toBe(1);
});

// --- the journal of a day ----------------------------------------------------------------------

it('shows the credits of the day with the lexeme, kind, source, points and who voided them, and the counts of the day', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 3);
    sapRepeat($learner, sapAt('2027-02-03 12:00'), interval: 9);
    $credits = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->get();
    app(ScoreCorrectionService::class)->void($learner, '2027-02-03', [$credits[0]->id], 'Подсказка учителя', $this->admin);
    $lexeme = Lexeme::query()->findOrFail($credits[3]->lexeme_id);

    sapJournal($learner)
        ->assertOk()
        ->assertCanSeeTableRecords($credits, inOrder: true)
        ->assertSee([$lexeme->key, 'Длинный повтор', 'Повтор в срок', 'Тренировка', '#7', 'Подсказка учителя', $this->admin->name])
        // The day: 3 + 3 + 5 after the void, under the Free limits of v2.
        ->assertSee(['11 оч. · новых 0 · повторов 3 · длинных 1', 'Правила v2', 'Исправлений дня'])
        ->assertTableActionVisible('restore', $credits[0])
        ->assertTableActionHidden('void', $credits[0])
        ->assertTableActionVisible('void', $credits[1]);
});

it('voids one credit (the day is recounted, audited), a repeat subtracts nothing twice, and the restore gives the points back', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 22); // 20 repetitions count (60), the last two are journaled with 0
    $ids = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->pluck('id')->all();
    $first = ScoreCredit::query()->findOrFail($ids[0]);

    sapJournal($learner)->callTableAction('void', $first, ['reason' => 'Ответ подсказан'])
        ->assertNotified('Начисления аннулированы: 1');
    // The replay refills the slot: the 21st credit gets its 3, the day stays at 60.
    expect(sapScoreDay($learner)->week_points)->toBe(60)
        ->and(ScoreCredit::query()->findOrFail($ids[20])->week_points)->toBe(3)
        ->and(AuditLog::query()->where('action', ScoreCorrectionService::ACTION_VOIDED)->sole()->meta['credits'])->toBe([$ids[0]]);

    // The selected ones: two more (one of them the cut 22nd), then the whole day twice - the second changes nothing.
    sapJournal($learner)->callTableBulkAction('voidSelected', [$ids[1], $ids[2]], ['reason' => 'Ответ подсказан'])->assertNotified('Начисления аннулированы: 2');
    expect(sapScoreDay($learner)->week_points)->toBe(57);

    sapJournal($learner)->callAction('voidDay', ['reason' => 'Скрипт'])->assertNotified('Начисления аннулированы: 19');
    expect(sapScoreDay($learner)->week_points)->toBe(0);
    $audits = AuditLog::query()->count();
    sapJournal($learner)->callAction('voidDay', ['reason' => 'Скрипт'])->assertNotified('Ничего не изменилось: начисления уже в этом состоянии');
    expect(sapScoreDay($learner)->week_points)->toBe(0)
        ->and(AuditLog::query()->count())->toBe($audits);

    sapJournal($learner)->callTableAction('restore', $first)->assertNotified('Начисления восстановлены: 1');
    expect(sapScoreDay($learner)->week_points)->toBe(3);
    sapJournal($learner)->callAction('restoreDay')->assertNotified('Начисления восстановлены: 21');
    expect(sapScoreDay($learner)->week_points)->toBe(60);
});

it('leaves the weekly count of a week that has ended as it is and still corrects the month', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 3);
    $credit = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->firstOrFail();
    $this->travelTo(sapAt('2027-02-20 12:00'));

    sapJournal($learner)->callTableAction('void', $credit, ['reason' => 'Поздно'])
        ->assertNotified(Notification::make()->success()->title('Начисления аннулированы: 1')
            ->body('Очки недели: 9 → 9, очки месяца: 9 → 6. Неделя этого дня уже закрыта: недельный зачёт не менялся.'));
    expect([sapScoreDay($learner)->week_points, sapScoreDay($learner)->month_points])->toBe([9, 6]);
});

it('words every refusal of the correction in Russian and shows one as a notification without failing', function (): void {
    foreach (['score_day_not_found', 'score_credit_not_found', 'score_reason_invalid', 'score_day_busy'] as $code) {
        expect(AdminErrors::text('learners.score_days', $code, ['max' => 255]))->toMatch('/[а-яё]/ui')->not->toContain('admin.')->not->toMatch('/:[a-z_]+/');
    }

    $learner = Learner::factory()->create();
    sapDay($learner, 2);
    $page = sapJournal($learner);
    // The day is gone meanwhile (the account purged by another administrator's action, a test double here).
    LearnerScoreDay::query()->where('learner_id', $learner->id)->delete();

    $page->callAction('voidDay', ['reason' => 'x'])->assertNotified('У ученика нет такого рейтингового дня.')->assertOk();
    // A reason over the limit never reaches the service.
    $other = Learner::factory()->create();
    sapDay($other, 2);
    sapJournal($other)->callAction('voidDay', ['reason' => str_repeat('я', 256)])->assertHasActionErrors(['reason' => 'max']);
});

// --- query budget of the new lists --------------------------------------------------------------

it('keeps the "Очки по дням" tab at a constant number of statements however many days there are', function (): void {
    $learner = Learner::factory()->create();

    $queries = FilamentQueryBudget::assert(ScoreDaysRelationManager::class, function (int $rows) use ($learner): void {
        $first = CarbonImmutable::parse('2027-02-01');
        for ($i = LearnerScoreDay::query()->where('learner_id', $learner->id)->count(); $i < $rows; $i++) {
            $day = $first->addDays($i)->toDateString();
            LearnerScoreDay::factory()->on($day)->create(['learner_id' => $learner->id, 'week_points' => 10, 'month_points' => 10, 'month_category' => $i % 2 === 0 ? ScoreCategory::Free : ScoreCategory::Pro]);
            ScoreCredit::factory()->create(['learner_id' => $learner->id, 'rating_day' => $day]);
            ScoreCredit::factory()->create(['learner_id' => $learner->id, 'rating_day' => $day, 'voided_at' => now()]);
        }
    }, ceiling: 6, parameters: ['ownerRecord' => $learner, 'pageClass' => ViewLearner::class]);

    expect(count($queries[FilamentQueryBudget::LARGE]))->toBe(count($queries[FilamentQueryBudget::SMALL]));
});

it('keeps the journal of a day at a constant number of statements however many credits there are', function (): void {
    $learner = Learner::factory()->create();
    sapDay($learner, 1);

    FilamentQueryBudget::assert(LearnerScoreDayCredits::class, function (int $rows) use ($learner): void {
        sapDay($learner, $rows);
        // Voided by two administrators: the names of both are loaded for the page in one statement.
        $ids = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->limit(4)->pluck('id')->all();
        app(ScoreCorrectionService::class)->void($learner, '2027-02-03', array_slice($ids, 0, 2), 'a', $this->admin);
        app(ScoreCorrectionService::class)->void($learner, '2027-02-03', array_slice($ids, 2, 2), 'b', User::factory()->create());
    }, ceiling: 14, parameters: ['learner' => $learner->id, 'day' => '2027-02-03']);
});

// --- the review of a v2 month ----------------------------------------------------------------------

/**
 * A pending v2 February: Free candidates with ranks `$free` (equal ranks = a tie), Pro with `$pro`.
 *
 * @param  list<int>  $free
 * @param  list<int>  $pro
 * @return array{month: LeagueMonth, free: list<LeagueMonthCandidate>, pro: list<LeagueMonthCandidate>}
 */
function sapMonth(array $free = [1, 2, 3, 4], array $pro = [1, 2, 3, 4]): array
{
    $month = LeagueMonth::factory()->startingOn('2027-02-01')->pendingReview()->create(['rules_version' => 2, 'participants_count' => 40]);
    $out = ['month' => $month, 'free' => [], 'pro' => []];
    foreach (['free' => $free, 'pro' => $pro] as $category => $ranks) {
        foreach ($ranks as $i => $rank) {
            $learner = Learner::factory()->create(['first_name' => ucfirst($category).'Learner'.($i + 1)]);
            $out[$category][] = LeagueMonthCandidate::factory()->create([
                'league_month_id' => $month->id,
                'learner_id' => $learner->id,
                'category' => $category,
                'rank' => $rank,
                'points' => 900 - $rank * 10,
                'active_days' => 20,
                'signals' => ['flags' => [], 'long_words' => 30 - $rank],
            ]);
        }
    }

    return $out;
}

it('shows a v2 month by category: the tabs, the candidates of the picked table, shared places, long repetitions, the prizes of both', function (): void {
    ['month' => $month] = sapMonth(free: [1, 2, 2, 4], pro: [1, 2, 3]);
    $month->forceFill(['prizes' => ['free' => [['place' => 1, 'ru' => 'Книга', 'uz' => 'Kitob']], 'pro' => [['place' => 1, 'ru' => 'Наушники', 'uz' => 'Quloqchin']]]])->save();

    livewire(LeagueMonthReview::class, ['month' => $month])
        ->assertOk()
        ->assertSee(['Free — призовых мест: 3', 'Pro — призовых мест: 3', 'Книга', 'Наушники', 'Длинных повторов', 'v2'])
        ->assertSee(['FreeLearner1', 'FreeLearner3', 'общее место'])
        ->assertDontSee('ProLearner1')
        ->set('category', 'pro')
        ->assertSee('ProLearner1')
        ->assertDontSee('FreeLearner1')
        ->assertDontSee('общее место')
        // An unknown value from the address shows Free.
        ->set('category', 'gold')
        ->assertSee('FreeLearner1');
});

it('edits 3 + 3 prizes of a v2 month: the form starts from both tables and saves {free, pro}', function (): void {
    ['month' => $month] = sapMonth();
    $month->forceFill(['prizes' => ['free' => [['place' => 2, 'ru' => 'Книга', 'uz' => 'Kitob']], 'pro' => [['place' => 1, 'ru' => 'Наушники', 'uz' => '']]]])->save();

    livewire(LeagueMonthReview::class, ['month' => $month])
        ->mountAction('editPrizes')
        ->assertSchemaStateSet(['free_ru_2' => 'Книга', 'free_uz_2' => 'Kitob', 'pro_ru_1' => 'Наушники', 'pro_ru_3' => '']);

    livewire(LeagueMonthReview::class, ['month' => $month])
        ->callAction('editPrizes', ['free_ru_1' => 'Сертификат', 'free_uz_1' => 'Sertifikat', 'free_ru_2' => '', 'free_uz_2' => '', 'pro_ru_1' => 'Наушники', 'pro_ru_3' => 'Книга'])
        ->assertNotified('Призы сохранены');

    expect($month->fresh()?->prizes)->toBe([
        'free' => [['place' => 1, 'ru' => 'Сертификат', 'uz' => 'Sertifikat']],
        'pro' => [['place' => 1, 'ru' => 'Наушники', 'uz' => ''], ['place' => 3, 'ru' => 'Книга', 'uz' => '']],
    ]);
});

it('refuses a tie beyond the reserve and confirms it only through the separate action with an explicit accept', function (): void {
    Event::fake([LeagueMonthConfirmed::class]);
    // Free: 1, 2, 2, 2, 2 - five winners where 3 places + 1 reserve allow four. Pro: no tie.
    ['month' => $month, 'free' => $free, 'pro' => $pro] = sapMonth(free: [1, 2, 2, 2, 2], pro: [1, 2, 3, 4]);

    $page = livewire(LeagueMonthReview::class, ['month' => $month])
        ->assertSee('Ничья на призовом месте выходит за резерв: Free — победителей 5 при 3 призовых местах (с резервом — не больше 4)')
        ->assertActionVisible('confirmTieOverflow');

    $page->callAction('confirm')
        ->assertNotified(__('admin.league_month.errors.tie_beyond_reserve', ['tie' => 'Free — победителей 5 при 3 призовых местах (с резервом — не больше 4)']));
    expect($month->fresh()?->status)->toBe('pending_review');

    livewire(LeagueMonthReview::class, ['month' => $month])->mountAction('confirmTieOverflow')->assertMountedActionModalSee(['Free', 'Pro', '2 место — FreeLearner5', 'Ничья сверх резерва']);
    $page->callAction('confirmTieOverflow', ['accept' => false])->assertHasActionErrors(['accept' => 'accepted']);
    expect($month->fresh()?->status)->toBe('pending_review');

    livewire(LeagueMonthReview::class, ['month' => $month])->callAction('confirmTieOverflow', ['accept' => true])->assertNotified('Итоги подтверждены');

    expect($month->fresh()?->status)->toBe('confirmed')
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('category', 'free')->where('status', LeagueMonthCandidateStatus::Winner->value)->count())->toBe(5)
        ->and(LeagueMonthCandidate::query()->where('league_month_id', $month->id)->where('category', 'pro')->where('status', LeagueMonthCandidateStatus::Winner->value)->pluck('place')->sort()->values()->all())->toBe([1, 2, 3])
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_CONFIRMED)->sole()->meta['tie_overflow_accepted'])->toBeTrue();
});

it('confirms a v2 month without a tie beyond the reserve by the ordinary action; the separate one is not offered', function (): void {
    Event::fake([LeagueMonthConfirmed::class]);
    ['month' => $month] = sapMonth(free: [1, 2, 2, 4], pro: [1, 2, 3, 4]);

    livewire(LeagueMonthReview::class, ['month' => $month])
        ->assertActionHidden('confirmTieOverflow')
        ->assertDontSee('выходит за резерв')
        ->mountAction('confirm')
        ->assertMountedActionModalSee(['1 место — FreeLearner1', '2 место — FreeLearner2', '2 место — FreeLearner3', '3 место — ProLearner3'])
        ->callMountedAction()
        ->assertNotified('Итоги подтверждены');

    expect($month->fresh()?->status)->toBe('confirmed')
        ->and(AuditLog::query()->where('action', MonthlyRatingReviewService::ACTION_CONFIRMED)->sole()->meta)->not->toHaveKey('tie_overflow_accepted');
    Event::assertDispatched(LeagueMonthConfirmed::class, fn (LeagueMonthConfirmed $e): bool => count($e->winners) === 6);
});

it('shows the rating days of a v2 candidate with the monthly count and the way to the journal of each day', function (): void {
    ['month' => $month, 'free' => $free] = sapMonth(free: [1], pro: []);
    LearnerScoreDay::factory()->on('2027-02-03')->create(['learner_id' => $free[0]->learner_id, 'month_points' => 90, 'month_new' => 3, 'month_review' => 20, 'month_long' => 4, 'raw_points' => 130, 'month_capped_at' => now()]);

    livewire(LeagueMonthReview::class, ['month' => $month])
        ->mountAction(TestAction::make('days')->arguments(['learner' => $free[0]->learner_id, 'name' => 'FreeLearner1']))
        ->assertMountedActionModalSee(['Баллы по дням: FreeLearner1', '03.02.2027', '90', '130', 'Журнал дня', "/admin/learners/{$free[0]->learner_id}/score-days/2027-02-03"]);
});

it('keeps the page of a v2 month at a constant number of statements however many candidates there are', function (): void {
    ['month' => $month] = sapMonth(free: [1], pro: [1]);
    $add = function (int $upTo) use ($month): void {
        for ($n = LeagueMonthCandidate::query()->where('league_month_id', $month->id)->count(); $n < $upTo; $n++) {
            LeagueMonthCandidate::factory()->create([
                'league_month_id' => $month->id, 'learner_id' => Learner::factory()->create()->id,
                'category' => $n % 2 === 0 ? 'free' : 'pro', 'rank' => intdiv($n, 2) + 1, 'signals' => ['flags' => ['too_fast'], 'long_words' => 3],
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
    $add(60);
    $large = $measure();

    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(8);
});

// --- the league widget ---------------------------------------------------------------------------------

it('shows the category of every group and the totals by category in the heading of a v2 week', function (): void {
    $this->travelTo(sapAt('2027-02-10 12:00'));
    $week = LeagueWeek::factory()->startingOn('2027-02-08')->create(['rules_version' => 2]);
    $free = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'category' => 'free', 'members_count' => 7]);
    $pro = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'category' => 'pro', 'members_count' => 3]);

    Livewire::test(LeagueGroupsTableWidget::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$free, $pro])
        ->assertSee('групп — 2, участников — 10 · правила v2; Free — групп 1, участников 7, Pro — групп 1, участников 3')
        ->assertSee(['Категория', 'Pro']);
});

it('keeps the heading of a v1 week as it was', function (): void {
    $this->travelTo(sapAt('2026-10-21 10:00'));
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create(['rules_version' => 1]);
    LeagueGroup::factory()->create(['league_week_id' => $week->id, 'members_count' => 7]);

    Livewire::test(LeagueGroupsTableWidget::class)
        ->assertSee('Лиги — неделя 19.10.2026 – 25.10.2026 (идёт): групп — 1, участников — 7')
        ->assertDontSee('правила v');
});

it('labels the score kinds the journal can show', function (): void {
    foreach (ScoreKind::cases() as $kind) {
        expect(__('admin.learners.score_days.kind.'.$kind->value))->not->toStartWith('admin.');
    }
    foreach (ScoreSource::cases() as $source) {
        expect(__('admin.learners.score_days.source.'.$source->value))->not->toStartWith('admin.');
    }
});
