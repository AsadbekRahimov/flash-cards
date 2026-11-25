<?php

declare(strict_types=1);

/*
 * "All time" (docs/32 §5, S10) and the tables of a region (docs/35 §2.4, R6-R7; owner's decisions docs/31 §5, §5г):
 *
 *   the totals    the monthly counts of the rating days v2 summed by the month's category, recomputed whole from the source -
 *                 800 + 600 Free and 950 Pro give 1 400 / 950 and a personal 2 350; a repeat writes nothing new; the close of a month
 *                 changes no sum; a correction of a day and the opt-out change them; the days of v1 never count;
 *   the runs      the scheduled run takes the learners whose rating days were written since the last run (a marker), the margin
 *                 catches a credit stamped before it;
 *   the region    fixed with the category at the first credit of the month, filled once by the first region chosen, never moved by
 *                 a change in the middle of the month; "all time" follows the current region;
 *   the tables    competition places 7, 7, 9 and stable pages; the threshold of a region (4 hidden, 5 shown); any region readable,
 *                 the viewer's own region only by its place; a cursor of another table → 422; no region → 422;
 *   off           no "all time", the month of v1 without any key of the regions.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreCorrectionService;
use App\Domain\Engagement\Services\Scoring\ScoreTotalsService;
use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\CardStateBefore;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Twa\Services\JwtService;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerLexeme;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\LearnerScoreTotal;
use App\Models\Lexeme;
use App\Models\ScoreCredit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.top', 3);
    config()->set('leagues.regions.min_participants', 5);
    Cache::forget(ScoreTotalsService::MARKER);
    $this->travelTo(atrNow());
});

function atrNow(string $local = '2027-04-10 12:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function atrLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', ...$attributes]);
}

function atrGet(object $test, Learner $learner, string $path): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->getJson($path);
}

/** A rating day v2 with its monthly count (and the week's, same) in `$category`, stamped `$at` (default: now). */
function atrDay(Learner $learner, string $day, int $points, string $category = 'free', array $extra = []): LearnerScoreDay
{
    return LearnerScoreDay::factory()->on($day)->create([
        'learner_id' => $learner->id, 'week_points' => min(100, $points), 'month_points' => $points,
        'week_category' => $category, 'month_category' => $category, ...$extra,
    ]);
}

function atrTotals(): ScoreTotalsService
{
    return app(ScoreTotalsService::class);
}

/** @return array<string, array{points: int, long_words: int, active_days: int, participant: bool, region: string|null}> */
function atrRows(Learner $learner): array
{
    $out = [];
    foreach (LearnerScoreTotal::query()->where('learner_id', $learner->id)->orderBy('category')->get() as $row) {
        $out[$row->category->value] = ['points' => $row->points, 'long_words' => $row->long_words, 'active_days' => $row->active_days, 'participant' => $row->participant, 'region' => $row->region_code];
    }

    return $out;
}

/** Learners of a table with the given points, recomputed (all in `$category`, `$region`). @return list<Learner> */
function atrTable(array $points, string $category = 'free', ?string $region = null, string $day = '2027-04-02'): array
{
    $learners = [];
    foreach ($points as $i => $p) {
        $learner = atrLearner(['first_name' => "L{$i}", 'region_code' => $region]);
        atrDay($learner, $day, $p, $category);
        $learners[] = $learner;
    }
    atrTotals()->reconcileAll(CarbonImmutable::now());

    return $learners;
}

// --- the totals ------------------------------------------------------------------------------------------------------------------

it('sums the monthly counts by the category of the month: 800 + 600 Free and 950 Pro give 1 400, 950 and 2 350', function (): void {
    $learner = atrLearner();
    atrDay($learner, '2027-02-03', 500);
    atrDay($learner, '2027-02-20', 300);
    atrDay($learner, '2027-03-05', 600);
    atrDay($learner, '2027-04-02', 950, 'pro');
    // A day of a month on the old rules (`shadow`, before the start) never counts: only the points v2 (owner's decision В1).
    atrDay($learner, '2027-01-20', 70, 'free', ['month_version' => 1]);
    // A day with a credit cut by the cap: a rating day, no active day.
    atrDay($learner, '2027-03-06', 0);

    atrTotals()->recomputeLearner($learner->id, CarbonImmutable::now());

    expect(atrRows($learner))->toBe([
        'free' => ['points' => 1400, 'long_words' => 0, 'active_days' => 3, 'participant' => true, 'region' => null],
        'pro' => ['points' => 950, 'long_words' => 0, 'active_days' => 1, 'participant' => true, 'region' => null],
    ]);
    $free = LearnerScoreTotal::query()->where('learner_id', $learner->id)->where('category', 'free')->sole();
    expect($free->first_day->toDateString())->toBe('2027-02-03')->and($free->last_day->toDateString())->toBe('2027-03-06');

    $body = atrGet($this, $learner, '/api/twa/league/all-time')->assertOk()->json();
    expect($body['my_total'])->toBe(['points' => 2350, 'free' => 1400, 'pro' => 950])
        ->and($body['period'])->toBe('all_time')
        ->and($body['counted_since'])->toBe('2027-02-01');
});

it('counts the long words of a category once for all time, from the marks of the lexemes', function (): void {
    $learner = atrLearner();
    atrDay($learner, '2027-03-05', 40);
    atrDay($learner, '2027-04-05', 40, 'pro');
    foreach ([['2027-03-05', null], ['2027-03-05', '2027-04-05'], [null, '2027-04-05']] as [$free, $pro]) {
        LearnerLexeme::factory()->create([
            'learner_id' => $learner->id, 'lexeme_id' => Lexeme::factory()->create()->id, 'confirmed_on' => '2027-02-01',
            'first_long_on' => $free ?? $pro, 'long_free_on' => $free, 'long_pro_on' => $pro,
        ]);
    }

    atrTotals()->recomputeLearner($learner->id, CarbonImmutable::now());

    expect(atrRows($learner)['free']['long_words'])->toBe(2)->and(atrRows($learner)['pro']['long_words'])->toBe(2);
});

it('is idempotent: a repeat gives the same rows and the reconcile writes nothing the second time; the close of a month changes no sum', function (): void {
    $months = app(MonthlyRatingService::class);
    $learner = atrLearner();
    atrDay($learner, '2027-03-05', 120);
    atrDay($learner, '2027-04-02', 30);

    expect(atrTotals()->reconcileAll(CarbonImmutable::now()))->toBe(1);
    $before = atrRows($learner);
    expect(atrTotals()->reconcileAll(CarbonImmutable::now()))->toBe(0);
    atrTotals()->recomputeLearner($learner->id, CarbonImmutable::now());
    expect(atrRows($learner))->toBe($before);

    // The close of April (May the 1st, after the delay) - the same sums.
    $april = $months->currentMonth(CarbonImmutable::now());
    $this->travelTo(atrNow('2027-05-01 04:00:00'));
    expect($months->closeDue(CarbonImmutable::now()))->toBeGreaterThanOrEqual(1)
        ->and(LeagueMonth::query()->findOrFail($april->id)->status)->toBe('pending_review')
        ->and(atrRows($learner))->toBe($before);
});

it('follows a correction of a day of a closed month and of the open one', function (): void {
    $learner = atrLearner();
    $lexemes = Lexeme::factory()->count(3)->create()->modelKeys();
    $recorder = app(AnswerScoreRecorder::class);
    foreach ($lexemes as $i => $lexeme) {
        LearnerLexeme::factory()->create(['learner_id' => $learner->id, 'lexeme_id' => $lexeme, 'confirmed_on' => '2027-03-01']);
        DB::transaction(fn () => $recorder->record(new ScoredAnswer(
            $learner->id, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed,
            new CardStateBefore(RepetitionState::Review, 3, CarbonImmutable::now()->subDays(3)->subHour()), Plan::Free, CarbonImmutable::now()->addSeconds($i),
        )));
    }
    atrTotals()->recomputeLearner($learner->id, CarbonImmutable::now());
    expect(atrRows($learner)['free']['points'])->toBe(9);

    $credit = ScoreCredit::query()->where('learner_id', $learner->id)->orderBy('id')->firstOrFail();
    app(ScoreCorrectionService::class)->void($learner, '2027-04-10', [$credit->id], 'test', User::factory()->create());
    expect(atrRows($learner)['free']['points'])->toBe(6);

    // A repeat subtracts nothing.
    app(ScoreCorrectionService::class)->void($learner, '2027-04-10', [$credit->id], 'test', User::factory()->create());
    expect(atrRows($learner)['free']['points'])->toBe(6);
});

it('takes an opted-out, blocked or deleted learner out of the table at once and keeps their own total', function (): void {
    [$a, $b, $c] = atrTable([90, 60, 30]);
    $settings = app(LearnerSettingsService::class);

    $settings->update($a, ['leagues_opt_out' => true]);
    expect(atrRows($a)['free']['participant'])->toBeFalse();

    $body = atrGet($this, $b, '/api/twa/league/all-time')->assertOk()->json();
    expect(array_column($body['top'], 'points'))->toBe([60, 30])
        ->and($body['me']['rank'])->toBe(1);

    $own = atrGet($this, $a->refresh(), '/api/twa/league/all-time')->assertOk()->json();
    expect($own['me']['participant'])->toBeFalse()
        ->and($own['me']['rank'])->toBeNull()
        ->and($own['me']['not_counted_reason'])->toBe('opted_out')
        ->and($own['my_total']['points'])->toBe(90)
        ->and($own['read_only'])->toBeTrue()
        ->and($own['neighbours'])->toBeNull();

    $settings->update($a->refresh(), ['leagues_opt_out' => false]);
    expect(atrRows($a)['free']['participant'])->toBeTrue();
});

// --- the scheduled run -----------------------------------------------------------------------------------------------------------

it('recomputes in the scheduled run only the learners whose rating days were written since the last run, with the margin', function (): void {
    config()->set('leagues.all_time.refresh_margin_seconds', 300);
    $old = atrLearner();
    atrDay($old, '2027-04-02', 50, 'free', ['updated_at' => atrNow('2027-04-02 10:00:00')]);
    $recent = atrLearner();
    atrDay($recent, '2027-04-10', 40, 'free', ['updated_at' => atrNow('2027-04-10 11:00:00')]);

    // No marker: the recent window of the month refresh (3 days) - only the learner of today.
    expect(atrTotals()->refreshRecent(CarbonImmutable::now()))->toBe(1)
        ->and(atrRows($old))->toBe([])
        ->and(atrRows($recent)['free']['points'])->toBe(40)
        ->and(Cache::get(ScoreTotalsService::MARKER))->toBe(atrNow()->toIso8601String());

    // Nothing written since: nothing recomputed (the day of 11:00 is outside the margin of the run of 12:00).
    $this->travelTo(atrNow('2027-04-10 12:10:00'));
    expect(atrTotals()->refreshRecent(CarbonImmutable::now()))->toBe(0);

    // A credit stamped at 12:08 that committed after the run of 12:10 started - the next run catches it by the margin.
    DB::table('learner_score_days')->where('learner_id', $recent->id)->update(['month_points' => 45, 'updated_at' => atrNow('2027-04-10 12:08:00')]);
    $this->travelTo(atrNow('2027-04-10 12:20:00'));
    expect(atrTotals()->refreshRecent(CarbonImmutable::now()))->toBe(1)
        ->and(atrRows($recent)['free']['points'])->toBe(45);

    // The full reconcile takes everybody else.
    expect(atrTotals()->reconcileAll(CarbonImmutable::now()))->toBe(1)->and(atrRows($old)['free']['points'])->toBe(50);
});

it('shows the viewer their own line fresh: a credit after the last run is in it at once', function (): void {
    [$a, $b] = atrTable([90, 60]);
    $this->travelTo(atrNow('2027-04-10 12:05:00'));
    DB::table('learner_score_days')->where('learner_id', $b->id)->update(['month_points' => 100, 'updated_at' => atrNow('2027-04-10 12:04:00')]);
    atrDay($b, '2027-04-10', 5);

    $body = atrGet($this, $b, '/api/twa/league/all-time')->assertOk()->json();
    expect($body['me']['points'])->toBe(105)->and($body['me']['rank'])->toBe(1);
});

it('is not shown off `live`: {enabled: false}; nothing is written in `off`', function (): void {
    $learner = atrLearner();
    atrDay($learner, '2027-04-02', 50);

    config()->set('leagues.scoring.mode', 'off');
    expect(atrTotals()->refreshRecent(CarbonImmutable::now()))->toBe(0)
        ->and(atrTotals()->reconcileAll(CarbonImmutable::now()))->toBe(0)
        ->and(LearnerScoreTotal::query()->count())->toBe(0);
    atrGet($this, $learner, '/api/twa/league/all-time')->assertOk()->assertExactJson(['enabled' => false, 'period' => 'all_time']);

    config()->set('leagues.scoring.mode', 'shadow');
    atrGet($this, $learner, '/api/twa/league/all-time')->assertOk()->assertExactJson(['enabled' => false, 'period' => 'all_time']);
});

// --- the table -------------------------------------------------------------------------------------------------------------------

it('gives competition places 1, 2, 2, 4 with stable pages and binds the cursor to its table', function (): void {
    config()->set('leagues.monthly.top', 2);
    $learners = atrTable([90, 70, 70, 50, 40]);
    $me = $learners[3];

    $body = atrGet($this, $me, '/api/twa/league/all-time')->assertOk()->json();
    expect(array_column($body['top'], 'rank'))->toBe([1, 2])
        ->and($body['participants'])->toBe(5)
        ->and($body['me']['rank'])->toBe(4)
        ->and($body['me']['next_gap'])->toBe(21)
        ->and($body['read_only'])->toBeFalse()
        ->and(array_column($body['neighbours']['above'], 'rank'))->toBe([2, 2])
        ->and(array_column($body['neighbours']['below'], 'rank'))->toBe([5]);

    $page = atrGet($this, $me, '/api/twa/league/all-time?after='.$body['next_cursor'])->assertOk()->json();
    expect(array_column($page['rows'], 'rank'))->toBe([2, 4, 5]);

    // A cursor of the month, of the other category or of a region is not one of this table.
    $cursor = new BoardCursor(70, 0, 1, $learners[1]->id);
    foreach ([BoardPageCursor::encode(1, ScoreCategory::Free, $cursor), BoardPageCursor::encodeAllTime(ScoreCategory::Pro, $cursor), BoardPageCursor::encodeAllTime(ScoreCategory::Free, $cursor, 'UZ-TK'), 'abc'] as $forged) {
        atrGet($this, $me, '/api/twa/league/all-time?after='.$forged)->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
    }
    atrGet($this, $me, '/api/twa/league/all-time?category=pro&after='.$body['next_cursor'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
});

it('opens the other category read-only, with the viewer\'s row of it when they have one', function (): void {
    $me = atrLearner();
    atrDay($me, '2027-03-02', 80);
    atrDay($me, '2027-04-02', 30, 'pro');
    LearnerScorePeriod::factory()->create(['learner_id' => $me->id, 'period' => 'month', 'starts_on' => '2027-04-01', 'category' => 'pro', 'rules_version' => 2]);
    atrTable([10]);

    $own = atrGet($this, $me, '/api/twa/league/all-time')->assertOk()->json();
    expect($own['category'])->toBe('pro')->and($own['my_category'])->toBe('pro')->and($own['me']['points'])->toBe(30);

    $free = atrGet($this, $me, '/api/twa/league/all-time?category=free')->assertOk()->json();
    expect($free['category'])->toBe('free')->and($free['me']['points'])->toBe(80)->and($free['me']['rank'])->toBe(1)->and($free['read_only'])->toBeFalse();
});

// --- the region ------------------------------------------------------------------------------------------------------------------

it('fixes the region of the month at its first credit; a change in the middle of the month moves only the next one and all time', function (): void {
    $learner = atrLearner(['region_code' => 'UZ-SA', 'region_changed_at' => atrNow()->subDays(40)]);
    $lexemes = Lexeme::factory()->count(2)->create()->modelKeys();
    $recorder = app(AnswerScoreRecorder::class);
    DB::transaction(fn () => $recorder->record(new ScoredAnswer($learner->id, $lexemes[0], 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Free, CarbonImmutable::now())));

    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->pluck('region_code', 'period')->all())->toEqual(['week' => 'UZ-SA', 'month' => 'UZ-SA']);

    $months = app(MonthlyRatingService::class);
    $april = $months->currentMonth(CarbonImmutable::now());
    $months->refresh($april, CarbonImmutable::now(), full: true);
    expect(LeagueMonthScore::query()->where('learner_id', $learner->id)->value('region_code'))->toBe('UZ-SA');

    app(LearnerRegionService::class)->set($learner, 'UZ-TK', LearnerRegionService::VIA_SETTINGS);

    // The month stays where its points were earned; all time follows the current region.
    $months->refresh($april, CarbonImmutable::now(), full: true);
    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->where('period', 'month')->value('region_code'))->toBe('UZ-SA')
        ->and(LeagueMonthScore::query()->where('learner_id', $learner->id)->value('region_code'))->toBe('UZ-SA')
        ->and(atrRows($learner)['free']['region'])->toBe('UZ-TK');

    // May: the first credit fixes the new region.
    $this->travelTo(atrNow('2027-05-02 12:00:00'));
    DB::transaction(fn () => $recorder->record(new ScoredAnswer($learner->id, $lexemes[1], 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Free, CarbonImmutable::now())));
    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->where('period', 'month')->whereDate('starts_on', '2027-05-01')->value('region_code'))->toBe('UZ-TK');

    // A reset to "not given" leaves the fixed month, takes all time out of the region.
    app(LearnerRegionService::class)->set($learner->refresh(), null, LearnerRegionService::VIA_SETTINGS);
    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->where('period', 'month')->whereDate('starts_on', '2027-05-01')->value('region_code'))->toBe('UZ-TK')
        ->and(atrRows($learner)['free']['region'])->toBeNull();
});

it('puts the first region chosen into the open month at once', function (): void {
    $months = app(MonthlyRatingService::class);
    $april = $months->currentMonth(CarbonImmutable::now());
    $learner = atrLearner();
    DB::transaction(fn () => app(AnswerScoreRecorder::class)->record(new ScoredAnswer($learner->id, Lexeme::factory()->create()->id, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, Plan::Free, CarbonImmutable::now())));
    $months->refresh($april, CarbonImmutable::now(), full: true);
    expect(LeagueMonthScore::query()->where('learner_id', $learner->id)->value('region_code'))->toBeNull();

    app(LearnerRegionService::class)->set($learner, 'UZ-FA', LearnerRegionService::VIA_PROMPT);

    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->where('period', 'month')->value('region_code'))->toBe('UZ-FA')
        ->and(LeagueMonthScore::query()->where('learner_id', $learner->id)->value('region_code'))->toBe('UZ-FA')
        ->and(atrRows($learner)['free']['region'])->toBe('UZ-FA');
});

it('hides a table of a region below five participants and shows it from five; any region is readable, read-only', function (): void {
    $tashkent = atrTable([50, 40, 30, 20], 'free', 'UZ-TK');
    $me = $tashkent[1];

    $hidden = atrGet($this, $me, '/api/twa/league/all-time?scope=region')->assertOk()->json();
    expect($hidden['scope'])->toBe('region')
        ->and($hidden['region'])->toMatchArray(['code' => 'UZ-TK', 'participants' => 4, 'shown' => false, 'min_participants' => 5])
        ->and($hidden['top'])->toBe([])
        ->and($hidden['next_cursor'])->toBeNull()
        ->and($hidden['neighbours'])->toBeNull()
        ->and($hidden['me']['region'])->toMatchArray(['code' => 'UZ-TK', 'rank' => null, 'shown' => false]);

    atrTable([10], 'free', 'UZ-TK');
    $shown = atrGet($this, $me, '/api/twa/league/all-time?scope=region')->assertOk()->json();
    expect($shown['region'])->toMatchArray(['participants' => 5, 'shown' => true])
        ->and(array_column($shown['top'], 'points'))->toBe([50, 40, 30])
        ->and($shown['me']['region']['rank'])->toBe(2)
        ->and($shown['read_only'])->toBeFalse()
        ->and($shown['neighbours'])->not->toBeNull();

    // Another region: readable, read-only, no neighbours; the viewer's own line stays theirs.
    atrTable([70, 60, 50, 40, 30], 'free', 'UZ-SA');
    $other = atrGet($this, $me, '/api/twa/league/all-time?scope=region&region=UZ-SA')->assertOk()->json();
    expect($other['region']['code'])->toBe('UZ-SA')
        ->and(array_column($other['top'], 'points'))->toBe([70, 60, 50])
        ->and($other['read_only'])->toBeTrue()
        ->and($other['neighbours'])->toBeNull()
        ->and(collect($other['top'])->where('is_me', true)->all())->toBe([])
        ->and($other['me']['region']['code'])->toBe('UZ-TK');

    // The page of a region continues only that region.
    $page = atrGet($this, $me, '/api/twa/league/all-time?scope=region&region=UZ-SA&after='.$other['next_cursor'])->assertOk()->json();
    expect(array_column($page['rows'], 'points'))->toBe([40, 30])->and($page['region']['code'])->toBe('UZ-SA')->and($page['read_only'])->toBeTrue();
    atrGet($this, $me, '/api/twa/league/all-time?scope=region&after='.$other['next_cursor'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
});

it('answers 422 to the own region without one, to an unknown region and to a region without the scope', function (): void {
    [$me] = atrTable([50]);

    atrGet($this, $me, '/api/twa/league/all-time?scope=region')->assertStatus(422)->assertJsonPath('error.code', 'region_not_set');
    atrGet($this, $me, '/api/twa/league/all-time?scope=region&region=XX-YY')->assertStatus(422);
    atrGet($this, $me, '/api/twa/league/all-time?region=UZ-TK')->assertStatus(422);
    atrGet($this, $me, '/api/twa/league/month?scope=region')->assertStatus(422)->assertJsonPath('error.code', 'region_not_set');
    // Somebody else's region needs no region of one's own.
    atrGet($this, $me, '/api/twa/league/all-time?scope=region&region=UZ-TK')->assertOk()->assertJsonPath('region.participants', 0);
});

// --- the month of a region -------------------------------------------------------------------------------------------------------

/** A v2 April with learners of the region `$region` (their months fixed there), refreshed. @return list<Learner> */
function atrMonthRegion(array $points, ?string $region, string $category = 'free'): array
{
    $learners = [];
    foreach ($points as $i => $p) {
        $learner = atrLearner(['first_name' => "M{$i}", 'region_code' => $region]);
        atrDay($learner, '2027-04-02', $p, $category);
        LearnerScorePeriod::factory()->create(['learner_id' => $learner->id, 'period' => 'month', 'starts_on' => '2027-04-01', 'category' => $category, 'rules_version' => 2, 'region_code' => $region]);
        $learners[] = $learner;
    }
    $months = app(MonthlyRatingService::class);
    $months->refresh($months->currentMonth(CarbonImmutable::now()), CarbonImmutable::now(), full: true);

    return $learners;
}

it('shows the table of a region of the month with the viewer\'s place in it next to the whole, without prizes, from five', function (): void {
    config()->set('leagues.monthly.prizes', [1 => ['ru' => 'Приз', 'uz' => 'Sovrin']]);
    $tk = atrMonthRegion([90, 70, 70, 50], 'UZ-TK');
    atrMonthRegion([100, 80], 'UZ-SA');
    $me = $tk[2];

    $whole = atrGet($this, $me, '/api/twa/league/month')->assertOk()->json();
    expect($whole['scope'])->toBe('all')->and($whole['region'])->toBeNull()
        ->and($whole['me']['rank'])->toBe(4)
        ->and($whole['me']['region'])->toMatchArray(['code' => 'UZ-TK', 'participants' => 4, 'shown' => false, 'rank' => null])
        ->and($whole['prizes'])->not->toBe([]);

    $hidden = atrGet($this, $me, '/api/twa/league/month?scope=region')->assertOk()->json();
    expect($hidden['region']['shown'])->toBeFalse()->and($hidden['top'])->toBe([])->and($hidden['prizes'])->toBe([])->and($hidden['neighbours'])->toBeNull();

    atrMonthRegion([10], 'UZ-TK');
    $shown = atrGet($this, $me, '/api/twa/league/month?scope=region')->assertOk()->json();
    expect($shown['region'])->toMatchArray(['code' => 'UZ-TK', 'participants' => 5, 'shown' => true])
        ->and(array_column($shown['top'], 'rank'))->toBe([1, 2, 2])
        ->and($shown['me']['region']['rank'])->toBe(2)
        ->and($shown['me']['rank'])->toBe(4)
        ->and($shown['read_only'])->toBeFalse()
        ->and($shown['prizes'])->toBe([])
        ->and(array_column($shown['neighbours']['above'], 'rank'))->toBe([1, 2])
        ->and($shown['participants'] ?? $shown['month']['participants'])->toBe(5);

    $page = atrGet($this, $me, '/api/twa/league/month?scope=region&after='.$shown['next_cursor'])->assertOk()->json();
    expect(array_column($page['rows'], 'points'))->toBe([50, 10])->and($page['scope'])->toBe('region');
    // A cursor of the region is not a cursor of the whole category, and the other way round.
    atrGet($this, $me, '/api/twa/league/month?after='.$shown['next_cursor'])->assertStatus(422);

    // Another region (two participants) - hidden, read-only.
    $other = atrGet($this, $me, '/api/twa/league/month?scope=region&region=UZ-SA')->assertOk()->json();
    expect($other['region'])->toMatchArray(['code' => 'UZ-SA', 'participants' => 2, 'shown' => false])->and($other['read_only'])->toBeTrue();
});

it('drops an opted-out learner from the table of the region of the month and from its neighbours', function (): void {
    $tk = atrMonthRegion([90, 80, 70, 60, 50, 40], 'UZ-TK');
    app(LearnerSettingsService::class)->update($tk[1], ['leagues_opt_out' => true]);

    $body = atrGet($this, $tk[2], '/api/twa/league/month?scope=region')->assertOk()->json();
    expect($body['region']['participants'])->toBe(5)
        ->and(array_column($body['top'], 'points'))->toBe([90, 70, 60])
        ->and($body['me']['region']['rank'])->toBe(2)
        ->and(array_column($body['neighbours']['above'], 'points'))->toBe([90]);
});

it('refuses a table of a region of a v1 month and keeps the shape of v1', function (): void {
    config()->set('leagues.scoring.mode', 'off');
    $learner = atrLearner(['region_code' => 'UZ-TK']);
    atrGet($this, $learner, '/api/twa/league/month?scope=region')->assertStatus(422)->assertJsonPath('error.code', 'region_scope_unavailable');

    $body = atrGet($this, $learner, '/api/twa/league/month')->assertOk()->json();
    expect($body)->not->toHaveKey('scope')->not->toHaveKey('region')->not->toHaveKey('rules_version');
});

it('drops every table of the month of a region with the tops of the month', function (): void {
    $keys = MonthlyRatingService::topKeys(7);
    expect($keys)->toContain('league:month:top:v2:7:free:r:UZ-TK', 'league:month:count:v1:7:pro:r:other', 'league:month:top:v2:7:pro')
        ->and(count($keys))->toBe(6 + 2 * 15 * 2)
        ->and(ScoreTotalsService::tableKeys())->toContain('league:alltime:top:v1:free', 'league:alltime:count:v1:pro:r:UZ-XO');
});

it('never lets an older recompute overwrite a newer one (two at once: the run and a hook)', function (): void {
    $learner = atrLearner();
    atrDay($learner, '2027-04-02', 50);
    atrTotals()->recomputeLearner($learner->id, atrNow('2027-04-10 12:00:10'));
    DB::table('learner_score_days')->where('learner_id', $learner->id)->update(['month_points' => 10]);

    // A recompute stamped before the row's last write (it started earlier and lost the race to it) writes nothing.
    atrTotals()->recomputeLearner($learner->id, atrNow('2027-04-10 12:00:05'));
    expect(atrRows($learner)['free']['points'])->toBe(50);

    atrTotals()->recomputeLearner($learner->id, atrNow('2027-04-10 12:00:10'));
    expect(atrRows($learner)['free']['points'])->toBe(10);
});

it('gives the periods of the open months their learner\'s region when the column arrives (the migration), not the closed ones', function (): void {
    $months = app(MonthlyRatingService::class);
    $april = $months->currentMonth(CarbonImmutable::now());
    $march = $months->currentMonth(atrNow('2027-03-10 12:00:00'));
    $march->forceFill(['status' => 'confirmed'])->save();
    $learner = atrLearner(['region_code' => 'UZ-BU']);
    foreach (['2027-03-01', '2027-04-01'] as $startsOn) {
        LearnerScorePeriod::factory()->create(['learner_id' => $learner->id, 'period' => 'month', 'starts_on' => $startsOn, 'category' => 'free', 'rules_version' => 2]);
    }

    $migration = require database_path('migrations/2026_11_27_200001_add_region_code_to_score_tables.php');
    foreach (['2026_11_27_200003_add_region_standings_index_to_learner_score_totals', '2026_11_27_200002_add_region_standings_index_to_league_month_scores'] as $file) {
        (require database_path("migrations/{$file}.php"))->down();
    }
    $migration->down();
    $migration->up();

    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->orderBy('starts_on')->pluck('region_code')->all())->toBe([null, 'UZ-BU'])
        ->and($april->isOpen())->toBeTrue();
});
