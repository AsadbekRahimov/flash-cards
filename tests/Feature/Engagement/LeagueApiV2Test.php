<?php

declare(strict_types=1);

/*
 * The API of the word points v2 (docs/32 §8, §12.7; task S12): the week of a v2 group (`rules_version`, the category, the gap, the
 * tie-break facts), the month of two tables (the own one by default, the other read-only, the pages by a cursor bound to the table,
 * the neighbours, the prizes and the participants of the table shown, the winners of the last month by table), "Today N of 100" in
 * the statement of scalars of `/today`, the points of a session in `/finish`, the emoji of Pro in the lists (docs/31 §5а) and the
 * nightly summary `score.credited`. The shapes of v1 / `off` are the contract tests (TwaContractTest, TwaLeagueTest,
 * TwaMonthlyRatingTest): the keys of v2 are absent there.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardPageCursor;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreKind;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\SessionScoreService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Friendship;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthCandidate;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\ProductEvent;
use App\Models\ScoreCredit;
use App\Models\Subscription;
use App\Models\TrainingReview;
use App\Models\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.top', 3);
    config()->set('leagues.monthly.categories', ['free' => ['places' => 3, 'tie_reserve' => 1], 'pro' => ['places' => 3, 'tie_reserve' => 1]]);
    config()->set('leagues.monthly.prizes', [1 => ['ru' => 'Приз', 'uz' => 'Sovrin']]);
    $this->travelTo(lavNow());
});

/** Wednesday of the first week of v2, noon in Tashkent. */
function lavNow(string $local = '2027-02-03 12:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function lavGet(object $test, Learner $learner, string $path): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->getJson($path);
}

function lavLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', ...$attributes]);
}

/** A live Pro of the learner's own until `$until` (a trial: the same rule of the plan). */
function lavPro(Learner $learner, CarbonImmutable $until): void
{
    Subscription::factory()->create([
        'subscriber_id' => $learner->id, 'provider' => 'trial', 'status' => 'trialing',
        'starts_at' => $until->subDays(14), 'current_period_ends_at' => $until,
    ]);
    app(EntitlementResolver::class)->forget($learner);
}

/** A rating day of the learner in the month of `lavNow()` (both counts on v2). */
function lavDay(Learner $learner, string $day, int $points, string $category = 'free', array $extra = []): void
{
    LearnerScoreDay::factory()->on($day)->create([
        'learner_id' => $learner->id, 'week_points' => $points, 'month_points' => $points,
        'week_category' => $category, 'month_category' => $category, ...$extra,
    ]);
}

/**
 * The v2 month of `lavNow()` with a Free table 50, 40, 40, 40, 30 (me), 20 and a Pro table 100, 60.
 *
 * @return array{me: Learner, free: list<Learner>, pro: list<Learner>, month: LeagueMonth}
 */
function lavMonth(): array
{
    $months = app(MonthlyRatingService::class);
    $month = $months->currentMonth(CarbonImmutable::now());
    $free = [];
    foreach ([50, 40, 40, 40, 30, 20] as $i => $points) {
        $free[$i] = lavLearner(['first_name' => "Free{$i}"]);
        lavDay($free[$i], '2027-02-02', $points);
    }
    $pro = [];
    foreach ([100, 60] as $i => $points) {
        $pro[$i] = lavLearner(['first_name' => "Pro{$i}"]);
        lavDay($pro[$i], '2027-02-02', $points, 'pro');
    }
    $months->refresh($month, CarbonImmutable::now(), full: true);

    return ['me' => $free[4], 'free' => $free, 'pro' => $pro, 'month' => $month];
}

// --- the week ----------------------------------------------------------------------------------------------------

it('shows a v2 week with its version, the category of the group, the gap and the tie-break facts of the rows', function (): void {
    $week = app(LeagueService::class)->currentWeek(CarbonImmutable::now());
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'category' => 'pro', 'members_count' => 3]);
    $me = lavLearner(['first_name' => 'Me']);
    $rival = lavLearner(['first_name' => 'Rival']);
    $twin = lavLearner(['first_name' => 'Twin']);
    foreach ([$me, $rival, $twin] as $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => 1, 'category' => 'pro']);
    }
    lavDay($rival, '2027-02-01', 50, 'pro', ['week_long' => 2]);
    lavDay($me, '2027-02-01', 30, 'pro');
    lavDay($twin, '2027-02-02', 30, 'pro');

    $response = lavGet($this, $me, '/api/twa/league')->assertOk();

    expect($response->json('rules_version'))->toBe(2)
        ->and($response->json('membership.category'))->toBe('pro')
        ->and($response->json('membership.rules_version'))->toBe(2)
        ->and($response->json('membership.rank'))->toBe(2)
        ->and($response->json('membership.next_gap'))->toBe(21)
        ->and($response->json('membership.standings'))->toBe([
            ['rank' => 1, 'name' => 'Rival', 'points' => 50, 'is_me' => false, 'pro_emoji' => null, 'long_words' => 2, 'active_days' => 1],
            ['rank' => 2, 'name' => 'Me', 'points' => 30, 'is_me' => true, 'pro_emoji' => null, 'long_words' => 0, 'active_days' => 1],
            ['rank' => 2, 'name' => 'Twin', 'points' => 30, 'is_me' => false, 'pro_emoji' => null, 'long_words' => 0, 'active_days' => 1],
        ]);
});

it('shows the emoji of a live Pro in the week, none of an expired one, and a new choice at once; one statement, none from the cache', function (): void {
    $week = app(LeagueService::class)->currentWeek(CarbonImmutable::now());
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0, 'category' => 'free', 'members_count' => 3]);
    $me = lavLearner();
    $pro = lavLearner(['pro_emoji' => 'fire']);
    $lapsed = lavLearner(['pro_emoji' => 'gem']);
    lavPro($pro, CarbonImmutable::now()->addDays(10));
    lavPro($lapsed, CarbonImmutable::now()->subDay());
    foreach ([$me, $pro, $lapsed] as $i => $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => 0, 'category' => 'free']);
        lavDay($learner, '2027-02-02', 10 * ($i + 1));
    }

    $miss = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/league')->assertOk()->assertJsonPath('membership.standings.1.pro_emoji', '🔥')->assertJsonPath('membership.standings.0.pro_emoji', null));
    $hit = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/league')->assertOk());
    expect(QueryLog::matching($miss, '/pro_emoji/'))->toHaveCount(1)
        ->and(QueryLog::matching($hit, '/pro_emoji|learner_score_days/'))->toBe([])
        ->and(count($hit))->toBe(count($miss) - 2); // the standings and the emoji

    // A new choice drops the emoji of the group: the next read shows it.
    app(LearnerSettingsService::class)->update($pro->fresh() ?? $pro, ['pro_emoji' => 'crown']);
    lavGet($this, $me, '/api/twa/league')->assertOk()->assertJsonPath('membership.standings.1.pro_emoji', '👑');
});

// --- the month ---------------------------------------------------------------------------------------------------

it('shows the viewer\'s table of a v2 month: the top with ties, the neighbours, the gap, the prizes and participants of the table', function (): void {
    ['me' => $me] = lavMonth();

    $response = lavGet($this, $me, '/api/twa/league/month')->assertOk();

    expect($response->json('rules_version'))->toBe(2)
        ->and($response->json('category'))->toBe('free')
        ->and($response->json('my_category'))->toBe('free')
        ->and($response->json('read_only'))->toBeFalse()
        ->and($response->json('month.participants'))->toBe(6)
        ->and($response->json('prizes'))->toBe([['place' => 1, 'title' => 'Приз']])
        ->and($response->json('top'))->toBe([
            ['rank' => 1, 'name' => 'Free0', 'points' => 50, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null, 'long_words' => 0],
            ['rank' => 2, 'name' => 'Free1', 'points' => 40, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null, 'long_words' => 0],
            ['rank' => 2, 'name' => 'Free2', 'points' => 40, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null, 'long_words' => 0],
        ])
        ->and($response->json('me.rank'))->toBe(5)
        ->and($response->json('me.category'))->toBe('free')
        ->and($response->json('me.long_words'))->toBe(0)
        ->and($response->json('me.next_gap'))->toBe(11)
        ->and(array_column($response->json('neighbours.above'), 'rank'))->toBe([2, 2])
        ->and(array_column($response->json('neighbours.below'), 'name'))->toBe(['Free5'])
        ->and($response->json('neighbours.below.0'))->toBe(['rank' => 6, 'name' => 'Free5', 'points' => 20, 'long_words' => 0, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null])
        ->and($response->json('next_cursor'))->toBeString();
});

it('opens the other table read-only: its top, prizes and participants, the viewer\'s own line, no neighbours', function (): void {
    ['me' => $me] = lavMonth();

    $response = lavGet($this, $me, '/api/twa/league/month?category=pro')->assertOk();

    expect($response->json('category'))->toBe('pro')
        ->and($response->json('my_category'))->toBe('free')
        ->and($response->json('read_only'))->toBeTrue()
        ->and($response->json('month.participants'))->toBe(2)
        ->and(array_column($response->json('top'), 'name'))->toBe(['Pro0', 'Pro1'])
        ->and($response->json('me.category'))->toBe('free')
        ->and($response->json('me.rank'))->toBe(5)
        ->and($response->json('neighbours'))->toBeNull()
        ->and($response->json('next_cursor'))->toBeNull();

    lavGet($this, $me, '/api/twa/league/month?category=gold')->assertStatus(422);
});

it('takes the table of the plan for a learner without a row of the month', function (): void {
    lavMonth();
    $newcomer = lavLearner();
    lavPro($newcomer, CarbonImmutable::now()->addDays(10));

    lavGet($this, $newcomer, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('my_category', 'pro')
        ->assertJsonPath('category', 'pro')
        ->assertJsonPath('me', null)
        ->assertJsonPath('neighbours', null);
});

it('pages a table by a cursor bound to it: places shared across pages, every row once, the order stable', function (): void {
    ['me' => $me, 'month' => $month, 'pro' => $pro] = lavMonth();

    $first = lavGet($this, $me, '/api/twa/league/month')->assertOk();
    $cursor = $first->json('next_cursor');
    $page = lavGet($this, $me, '/api/twa/league/month?category=free&after='.urlencode($cursor))->assertOk()
        ->assertExactJsonStructure(['rules_version', 'category', 'read_only', 'rows' => ['*' => ['rank', 'name', 'points', 'long_words', 'active_days', 'is_me', 'pro_emoji']], 'next_cursor']);

    expect($page->json('rows'))->toBe([
        ['rank' => 2, 'name' => 'Free3', 'points' => 40, 'long_words' => 0, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null],
        ['rank' => 5, 'name' => 'Free4', 'points' => 30, 'long_words' => 0, 'active_days' => 1, 'is_me' => true, 'pro_emoji' => null],
        ['rank' => 6, 'name' => 'Free5', 'points' => 20, 'long_words' => 0, 'active_days' => 1, 'is_me' => false, 'pro_emoji' => null],
    ])
        ->and($page->json('next_cursor'))->toBeNull()
        ->and($page->json('read_only'))->toBeFalse();

    // The same cursor again: the same page (a stable order of a shared place).
    expect(lavGet($this, $me, '/api/twa/league/month?after='.urlencode($cursor))->json('rows'))->toBe($page->json('rows'));

    // A broken cursor, a cursor of the other table or of another month: 422, never a page of something else.
    $proCursor = BoardPageCursor::encode($month->id, ScoreCategory::Pro, new BoardCursor(100, 0, 1, $pro[0]->id));
    $old = BoardPageCursor::encode($month->id + 1, ScoreCategory::Free, new BoardCursor(40, 0, 1, 1));
    foreach (['nonsense', 'm'.$month->id.'f.!!', $proCursor, $old, substr((string) $cursor, 0, -3)] as $bad) {
        lavGet($this, $me, '/api/twa/league/month?category=free&after='.urlencode($bad))->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
    }
    // The other table's own cursor is its page, read-only.
    lavGet($this, $me, '/api/twa/league/month?category=pro&after='.urlencode($proCursor))->assertOk()
        ->assertJsonPath('read_only', true)->assertJsonPath('rows.0.name', 'Pro1');
});

it('has no pages in a v1 month', function (): void {
    config()->set('leagues.scoring.mode', 'off');
    $me = lavLearner();

    lavGet($this, $me, '/api/twa/league/month')->assertOk()->assertJsonMissingPath('rules_version')->assertJsonMissingPath('category');
    lavGet($this, $me, '/api/twa/league/month?after='.urlencode(BoardPageCursor::encode(1, ScoreCategory::Free, new BoardCursor(1, 0, 1, 1))))
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
});

it('reads the month in a pinned number of statements: the top and the neighbours with their emoji cached, a page +1 for its emoji', function (): void {
    ['me' => $me, 'free' => $free] = lavMonth();
    $free[2]->forceFill(['pro_emoji' => 'fire'])->save();
    lavPro($free[2], CarbonImmutable::now()->addDays(10));

    $miss = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('top.2.pro_emoji', '🔥')->assertJsonPath('neighbours.above.0.pro_emoji', '🔥'));
    $hit = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/league/month')->assertOk()
        ->assertJsonPath('top.2.pro_emoji', '🔥')->assertJsonPath('neighbours.above.0.pro_emoji', '🔥'));

    // A miss reads the emoji twice (the top, the neighbours); the cache: none, and nothing of the board (the top, the place, the
    // neighbours and the gap: 1 + 1 + 6 statements, plus the two of the emoji).
    expect(QueryLog::matching($miss, '/pro_emoji/'))->toHaveCount(2)
        ->and(QueryLog::matching($hit, '/pro_emoji/'))->toBe([])
        ->and(count($miss) - count($hit))->toBe(10);

    $cursor = (string) lavGet($this, $me, '/api/twa/league/month')->json('next_cursor');
    $page = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/league/month?after='.urlencode($cursor))->assertOk());
    // the learner (auth), the month, the own row, the board after a cursor (3), the emoji of the page (1)
    expect(QueryLog::matching($page, '/pro_emoji/'))->toHaveCount(1)
        ->and(QueryLog::matching($page, '/from ["`]league_month_scores["`]/'))->toHaveCount(4);
});

it('shows a new emoji choice in the top of the month at once', function (): void {
    ['me' => $me, 'free' => $free] = lavMonth();
    lavPro($free[0], CarbonImmutable::now()->addDays(10));
    lavGet($this, $me, '/api/twa/league/month')->assertOk()->assertJsonPath('top.0.pro_emoji', null);

    app(LearnerSettingsService::class)->update($free[0], ['pro_emoji' => 'owl']);

    lavGet($this, $me, '/api/twa/league/month')->assertOk()->assertJsonPath('top.0.pro_emoji', '🦉');
});

it('splits the winners of a v2 last month by table', function (): void {
    $last = LeagueMonth::factory()->startingOn('2027-01-01')->confirmed()->create(['rules_version' => 2]);
    $freeWinner = lavLearner(['first_name' => 'Fw']);
    $proWinner = lavLearner(['first_name' => 'Pw']);
    LeagueMonthCandidate::factory()->winner(1)->create(['league_month_id' => $last->id, 'learner_id' => $freeWinner->id, 'category' => 'free', 'points' => 900]);
    LeagueMonthCandidate::factory()->winner(1)->create(['league_month_id' => $last->id, 'learner_id' => $proWinner->id, 'category' => 'pro', 'points' => 1200]);
    $me = lavLearner();

    $response = lavGet($this, $me, '/api/twa/league/month')->assertOk();
    expect($response->json('last_month.winners'))->toBe([['place' => 1, 'name' => 'Fw', 'points' => 900]])
        ->and($response->json('last_month.winners_by_category'))->toBe([
            'free' => [['place' => 1, 'name' => 'Fw', 'points' => 900]],
            'pro' => [['place' => 1, 'name' => 'Pw', 'points' => 1200]],
        ]);
    expect(lavGet($this, $me, '/api/twa/league/month?category=pro')->json('last_month.winners'))->toBe([['place' => 1, 'name' => 'Pw', 'points' => 1200]]);
});

// --- Today, the finish -------------------------------------------------------------------------------------------

it('shows "Today N of 100" in /today in live from the day row, the fixed category without one, nothing outside live', function (): void {
    $me = lavLearner();
    lavDay($me, '2027-02-03', 40, 'free', ['week_new' => 5, 'week_review' => 6, 'month_new' => 5, 'month_review' => 6]);

    lavGet($this, $me, '/api/twa/today')->assertOk()->assertJsonPath('score_today', [
        'points' => 40, 'cap' => 100, 'category' => 'free', 'new' => [5, 20], 'review' => [6, 20], 'remaining' => 60,
        'day_ends_at' => '2027-02-03T19:00:00+00:00', 'month' => null,
    ]);

    // A learner whose week and month were fixed as Pro by an earlier credit, no credit today: 0 of 150 - not the cap of the plan now.
    $pro = lavLearner();
    foreach ([['week', '2027-02-01'], ['month', '2027-02-01']] as [$period, $startsOn]) {
        LearnerScorePeriod::query()->create(['learner_id' => $pro->id, 'period' => $period, 'starts_on' => $startsOn, 'category' => 'pro', 'rules_version' => 2, 'fixed_at' => now()]);
    }
    lavGet($this, $pro, '/api/twa/today')->assertOk()
        ->assertJsonPath('score_today.points', 0)->assertJsonPath('score_today.cap', 150)->assertJsonPath('score_today.category', 'pro');

    // A Free learner of nothing yet: the plan's table.
    lavGet($this, lavLearner(), '/api/twa/today')->assertOk()->assertJsonPath('score_today.cap', 100);

    foreach (['off', 'shadow'] as $mode) {
        config()->set('leagues.scoring.mode', $mode);
        lavGet($this, $me, '/api/twa/today')->assertOk()->assertJsonMissingPath('score_today');
    }
    // live before the start: the day is on v1.
    config()->set('leagues.scoring.mode', 'live');
    $this->travelTo(lavNow('2027-01-27 12:00'));
    lavGet($this, lavLearner(), '/api/twa/today')->assertOk()->assertJsonMissingPath('score_today');
});

it('adds no statement to /today for "Today N of 100": the reads ride in the statement of scalars', function (): void {
    $me = lavLearner();
    lavDay($me, '2027-02-03', 40);

    $count = function () use ($me): int {
        lavGet($this, $me, '/api/twa/today')->assertOk(); // warm

        return count(QueryLog::during(fn () => lavGet($this, $me, '/api/twa/today')->assertOk()));
    };

    config()->set('leagues.scoring.mode', 'off');
    $off = $count();
    config()->set('leagues.scoring.mode', 'live');
    $live = $count();

    expect($live)->toBe($off);
});

it('tells in /finish what the session earned, what each count got, the growth of the week and the day after it', function (): void {
    $me = lavLearner();
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($me->id)['token']];
    $session = TrainingSession::factory()->personal($me, 'daily_review')->create(['started_at' => now()->subMinutes(20)]);
    TrainingReview::factory()->count(4)->create(['training_session_id' => $session->id, 'learner_id' => $me->id, 'quality' => 4]);
    // The week: Monday 100, Tuesday 90, today 98 of which the session brought 2 + 2 + 5 + 0 (the cap cut the last one).
    lavDay($me, '2027-02-01', 100);
    lavDay($me, '2027-02-02', 90);
    lavDay($me, '2027-02-03', 98, 'free', ['week_new' => 2, 'week_review' => 2]);
    foreach ([[ScoreKind::New, 2, 2], [ScoreKind::New, 2, 2], [ScoreKind::Long, 5, 5], [ScoreKind::Review, 0, 0]] as [$kind, $week, $month]) {
        ScoreCredit::factory()->create(['learner_id' => $me->id, 'rating_day' => '2027-02-03', 'source' => ScoreSource::Training, 'source_id' => $session->id, 'kind' => $kind, 'week_points' => $week, 'month_points' => $month]);
    }
    // Another session's credit and a voided one of this session: not the session's.
    ScoreCredit::factory()->create(['learner_id' => $me->id, 'rating_day' => '2027-02-03', 'source_id' => $session->id + 1, 'week_points' => 2]);
    ScoreCredit::factory()->create(['learner_id' => $me->id, 'rating_day' => '2027-02-03', 'source_id' => $session->id, 'week_points' => 2, 'voided_at' => now()]);

    $response = $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$session->id}/finish")->assertOk();

    expect($response->json('score_session'))->toBe([
        'earned' => 12, 'week_added' => 9, 'month_added' => 9, 'week_rating_delta' => 9, 'capped_off' => 3,
        'today' => ['points' => 98, 'cap' => 100, 'category' => 'free', 'new' => [2, 20], 'review' => [2, 20], 'remaining' => 2, 'day_ends_at' => '2027-02-03T19:00:00+00:00', 'month' => null],
    ])
        ->and($response->json('league_points'))->toBe(9);

    // Off: the key is absent, `league_points` is the v1 formula again.
    config()->set('leagues.scoring.mode', 'off');
    $this->withHeaders($headers)->postJson("/api/twa/training/sessions/{$session->id}/finish")->assertOk()
        ->assertJsonMissingPath('score_session')->assertJsonPath('league_points', 8);
});

it('counts the growth of the week by the best days only: a session on a weak day adds less to the week', function (): void {
    config()->set('leagues.scoring.versions.2.best_days', 2);
    $me = lavLearner();
    $session = TrainingSession::factory()->personal($me, 'daily_review')->create(['started_at' => now()->subMinutes(20)]);
    TrainingReview::factory()->create(['training_session_id' => $session->id, 'learner_id' => $me->id, 'quality' => 4]);
    lavDay($me, '2027-02-01', 100);
    lavDay($me, '2027-02-02', 90);
    lavDay($me, '2027-02-03', 95);
    ScoreCredit::factory()->create(['learner_id' => $me->id, 'rating_day' => '2027-02-03', 'source_id' => $session->id, 'kind' => ScoreKind::Long, 'week_points' => 5, 'month_points' => 5]);

    $score = app(SessionScoreService::class)->forSession($session, 1, CarbonImmutable::now());

    // best two days: 100 + 95 now, 100 + 90 without the session (today would be 90) - +5 to the week; nothing for a session without answers
    expect($score?->weekRatingDelta)->toBe(5)
        ->and(app(SessionScoreService::class)->forSession($session, 0, CarbonImmutable::now()))->toBeNull();
});

// --- friends --------------------------------------------------------------------------------------------------------

it('shows the emoji of a friend with a live Pro in the list of friends, in one statement', function (): void {
    $me = lavLearner();
    $friend = lavLearner(['pro_emoji' => 'rocket']);
    $other = lavLearner(['pro_emoji' => 'rocket']); // no Pro: the choice is kept, not shown
    lavPro($friend, CarbonImmutable::now()->addDays(3));
    Friendship::factory()->between($me, $friend)->create();
    Friendship::factory()->between($me, $other)->create();

    $queries = QueryLog::during(fn () => lavGet($this, $me, '/api/twa/friends')->assertOk());
    $emoji = array_column(lavGet($this, $me, '/api/twa/friends')->json('friends'), 'pro_emoji');
    sort($emoji);

    expect(QueryLog::matching($queries, '/pro_emoji/'))->toHaveCount(1)
        ->and($emoji)->toBe([null, '🚀']);
});

// --- score.credited ----------------------------------------------------------------------------------------------

it('records score.credited once per rating day: learners, credits, percentiles and the share of capped days per table', function (): void {
    $yesterday = '2027-02-02';
    foreach ([[10, null], [50, null], [100, now()]] as [$points, $capped]) {
        lavDay(lavLearner(), $yesterday, $points, 'free', ['week_capped_at' => $capped, 'month_capped_at' => $capped, 'new_count' => 2, 'review_count' => 1]);
    }
    lavDay(lavLearner(), $yesterday, 150, 'pro', ['week_capped_at' => now(), 'month_capped_at' => now(), 'new_count' => 30, 'review_count' => 30]);
    lavDay(lavLearner(), '2027-02-03', 70); // today: not reported

    $this->artisan('leagues:report-score-day')->assertSuccessful();
    $this->artisan('leagues:report-score-day')->assertSuccessful(); // a repeated run
    $this->artisan('leagues:report-score-day', ['--day' => $yesterday])->assertSuccessful();

    $events = ProductEvent::query()->where('name', 'score.credited')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBeNull()
        ->and($events[0]->props)->toMatchArray([
            'rating_day' => $yesterday, 'learners' => 4, 'credits' => 69,
            'free_learners' => 3, 'free_credits' => 9, 'pro_learners' => 1, 'pro_credits' => 60,
            'week_free_p50' => 50, 'week_free_p90' => 100, 'week_free_capped_share' => 0.333,
            'month_pro_p50' => 150, 'month_pro_p90' => 150, 'month_pro_capped_share' => 1,
        ]);

    // Another day is its own event; the points off - nothing at all.
    $this->artisan('leagues:report-score-day', ['--day' => '2027-02-03'])->assertSuccessful();
    config()->set('leagues.scoring.mode', 'off');
    $this->artisan('leagues:report-score-day', ['--day' => '2027-02-01'])->assertSuccessful();
    expect(ProductEvent::query()->where('name', 'score.credited')->count())->toBe(2);

    $this->artisan('leagues:report-score-day', ['--day' => '2027-02-30'])->assertExitCode(2);
});

it('schedules score.credited at 00:30 of the league zone', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'leagues:report-score-day'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 0 * * *')
        ->and((string) $event->timezone)->toBe('Asia/Tashkent');
});
