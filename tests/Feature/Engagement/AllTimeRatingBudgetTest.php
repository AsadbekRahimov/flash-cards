<?php

declare(strict_types=1);

/*
 * The statement budget of "all time" (docs/32 §12.11, S10) and of the tables of a region (docs/35 §2.4, R6-R7), each path from empty
 * caches (cold) and again (warm), for a table of 11 and of 61 participants: the numbers do not grow with the table. The plans behind
 * them - docs/32 §12.11 (PostgreSQL, 50 000 learners).
 *
 *   GET /league/all-time                  13 / 2   auth, the viewer's rows (with the moment their days changed and the category of the
 *                                                  month: one statement), participants, place, participants and place in the region,
 *                                                  neighbours (place of the row above, position, above, below), their emoji, the top and
 *                                                  its emoji / auth and the viewer's rows;
 *   GET /league/all-time?scope=region     12 / 2   the same with the region's count, top and neighbours (the whole-category place stays:
 *                                                  `me.rank`; the whole-category count does not);
 *   GET /league/all-time?after=            7       auth, the category of the month, the participants of the table, the viewer's row,
 *                                                  the page (3), its emoji - minus the cached count;
 *   GET /league/month (me in a region)    15 / 4   S12's 13 / 4 + the participants and the place in the region (both cached);
 *   GET /league/month?scope=region        14 / 4.
 *
 * The writes: the recompute of one learner - 1; the scheduled run - 1 (plus the marker on the cache); the reconcile - 1 + 1 per batch;
 * a change of the region - the recompute, the open months, the fill (+ the recount of the month when filled).
 */

use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreTotalsService;
use App\Domain\Learners\Services\LearnerRegionService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.top', 3);
    Cache::forget(ScoreTotalsService::MARKER);
    $this->travelTo(atbNow());
});

function atbNow(string $local = '2027-04-10 12:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

/** A learner with a rating day of April written on the 2nd, the month fixed in `$region`. */
function atbLearner(int $points, ?string $region, string $name = 'L'): Learner
{
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'region_code' => $region, 'first_name' => $name]);
    LearnerScoreDay::factory()->on('2027-04-02')->create([
        'learner_id' => $learner->id, 'month_points' => $points, 'week_points' => min(100, $points), 'updated_at' => atbNow('2027-04-02 10:00'),
    ]);
    LearnerScorePeriod::factory()->create(['learner_id' => $learner->id, 'period' => 'month', 'starts_on' => '2027-04-01', 'category' => 'free', 'rules_version' => 2, 'region_code' => $region]);

    return $learner;
}

/** The viewer (500 points, Tashkent) among `$others` around them, two thirds in Tashkent; every cache empty. */
function atbTable(int $others): Learner
{
    $me = atbLearner(500, 'UZ-TK', 'Me');
    foreach (range(1, $others) as $i) {
        atbLearner(500 + ($i % 2 === 0 ? $i : -$i), $i % 3 === 0 ? 'UZ-SA' : 'UZ-TK');
    }
    app(ScoreTotalsService::class)->reconcileAll(CarbonImmutable::now());
    $months = app(MonthlyRatingService::class);
    $month = $months->currentMonth(CarbonImmutable::now());
    $months->refresh($month, CarbonImmutable::now(), full: true);
    PureCache::forgetMany([...MonthlyRatingService::topKeys($month->id), ...ScoreTotalsService::tableKeys()]);

    return $me;
}

it('reads every table in a number of statements that does not grow with it, cold and warm', function (int $others): void {
    $me = atbTable($others);
    $auth = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($me->id)['token']];
    $get = fn (string $path): array => QueryLog::during(fn () => $this->withHeaders($auth)->getJson($path)->assertOk());

    foreach ([
        '/api/twa/league/all-time' => [13, 2],
        '/api/twa/league/all-time?scope=region' => [12, 2],
        '/api/twa/league/month' => [15, 4],
        '/api/twa/league/month?scope=region' => [14, 4],
    ] as $path => [$cold, $warm]) {
        // Each path from empty caches: nothing it shares with another path (the viewer's place, the counts) is warm.
        PureCache::store()->flush();
        $miss = $get($path);
        $hit = $get($path);
        expect($miss)->toHaveCount($cold)
            ->and($hit)->toHaveCount($warm)
            // nothing written by a read: the viewer's rows are fresh
            ->and(QueryLog::matching([...$miss, ...$hit], '/^(insert|update|delete)/i'))->toBe([]);
    }

    $cursor = $this->withHeaders($auth)->getJson('/api/twa/league/all-time')->json('next_cursor');
    expect($get('/api/twa/league/all-time?after='.$cursor))->toHaveCount(7);
})->with([10, 60]);

it('recomputes the viewer\'s own rows on a read only when a rating day of theirs changed after them: 2 statements more', function (): void {
    $me = atbTable(10);
    $auth = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($me->id)['token']];
    $this->withHeaders($auth)->getJson('/api/twa/league/all-time')->assertOk();

    // A credit of today, after the last recompute.
    $this->travelTo(atbNow('2027-04-10 12:05'));
    LearnerScoreDay::factory()->on('2027-04-10')->create(['learner_id' => $me->id, 'month_points' => 7, 'week_points' => 7, 'updated_at' => atbNow('2027-04-10 12:04')]);
    $stale = QueryLog::during(fn () => $this->withHeaders($auth)->getJson('/api/twa/league/all-time')->assertOk()->assertJsonPath('me.points', 507));
    expect(QueryLog::matching($stale, '/^insert into "?learner_score_totals/i'))->toHaveCount(1);

    $fresh = QueryLog::during(fn () => $this->withHeaders($auth)->getJson('/api/twa/league/all-time')->assertOk()->assertJsonPath('me.points', 507));
    expect(QueryLog::matching($fresh, '/^insert/i'))->toBe([]);
});

it('writes the totals in one statement per set of learners', function (): void {
    $learners = [atbLearner(10, null), atbLearner(20, 'UZ-TK'), atbLearner(30, 'UZ-SA')];
    $totals = app(ScoreTotalsService::class);

    expect(QueryLog::during(fn () => $totals->recomputeLearner($learners[0]->id, CarbonImmutable::now())))->toHaveCount(1)
        ->and(QueryLog::during(fn () => $totals->refreshRecent(CarbonImmutable::now())))->toHaveCount(1);

    config()->set('leagues.all_time.reconcile_batch', 100);
    // the highest learner id, then one statement per 100 ids
    expect(QueryLog::during(fn () => $totals->reconcileAll(CarbonImmutable::now())))->toHaveCount(1 + intdiv($learners[2]->id, 100) + 1);

    // off: nothing at all
    config()->set('leagues.scoring.mode', 'off');
    expect(QueryLog::during(fn () => $totals->refreshRecent(CarbonImmutable::now())))->toBe([])
        ->and(QueryLog::during(fn () => $totals->recomputeLearner($learners[0]->id, CarbonImmutable::now())))->toBe([]);
});

it('spends on a change of the region the recompute, the open months and the fill; the recount of the month only when filled', function (): void {
    $months = app(MonthlyRatingService::class);
    $months->currentMonth(CarbonImmutable::now());
    $learner = Learner::factory()->onboarded()->create(['region_code' => 'UZ-SA', 'region_changed_at' => atbNow()->subDays(40)]);
    $service = app(LearnerRegionService::class);

    // A change with the month fixed in a region already: event, totals recompute, the open months, the fill (0 rows).
    $change = QueryLog::during(fn () => $service->recordChange($learner, ['from' => 'UZ-TK', 'to' => 'UZ-SA'], LearnerRegionService::VIA_SETTINGS));
    expect(QueryLog::matching($change, '/^insert into "?learner_score_totals/i'))->toHaveCount(1)
        ->and(QueryLog::matching($change, '/^update "?learner_score_periods/i'))->toHaveCount(1)
        ->and(QueryLog::matching($change, '/^insert into "?league_month_scores/i'))->toBe([]);

    config()->set('leagues.scoring.mode', 'off');
    $off = QueryLog::during(fn () => $service->recordChange($learner, ['from' => 'UZ-TK', 'to' => 'UZ-SA'], LearnerRegionService::VIA_SETTINGS));
    expect(QueryLog::matching($off, '/learner_score_totals|learner_score_periods|league_month_scores/i'))->toBe([]);
});
