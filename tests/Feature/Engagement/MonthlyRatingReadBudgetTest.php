<?php

declare(strict_types=1);

/*
 * The read budget of the monthly rating after the SQL audit (docs/38 §3.1-§3.2):
 *
 *   the viewer's row     ONE statement while the row is newer than the learner's rating days of the month (no `FOR SHARE`, no upsert,
 *                        no transaction); the recompute only when a day changed, and for a learner without a rating day - never;
 *   the participants     counted on a miss of the top only (`league:month:count:v1:*`, dropped with the top);
 *   the neighbours       the own place is counted once (the caller's), the gap is read off the rows above;
 *   the emoji of Pro     both "who is Pro" subqueries narrowed to the ids of the page - the same answer as the plan gives.
 *
 * The numbers do not grow with the size of the table.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Engagement\DTO\BoardCursor;
use App\Domain\Engagement\DTO\BoardScope;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Services\MonthlyRatingReviewService;
use App\Domain\Engagement\Services\MonthlyRatingService;
use App\Domain\Engagement\Services\Scoring\ScoreBoard;
use App\Domain\Learners\Services\ProEmojiService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Twa\Services\JwtService;
use App\Models\LeagueMonth;
use App\Models\LeagueMonthScore;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.monthly.top', 3);
    config()->set('leagues.monthly.min_active_days', 1);
    config()->set('leagues.monthly.min_account_days', 0);
    config()->set('leagues.monthly.candidates', 3);
    $this->travelTo(mrbNow());
});

function mrbNow(string $local = '2027-02-10 12:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function mrb(): MonthlyRatingService
{
    return app(MonthlyRatingService::class);
}

function mrbLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', ...$attributes]);
}

/** A rating day of February 2027 written at `$at` (the stamp a credit leaves). */
function mrbDay(Learner $learner, string $day, int $points, ?CarbonImmutable $at = null, string $category = 'free', int $long = 0): LearnerScoreDay
{
    $at ??= CarbonImmutable::now();

    return LearnerScoreDay::factory()->on($day)->create([
        'learner_id' => $learner->id, 'week_points' => $points, 'month_points' => $points, 'month_long' => $long,
        'week_category' => $category, 'month_category' => $category, 'created_at' => $at, 'updated_at' => $at,
    ]);
}

/** @return array<string, string> */
function mrbAuth(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/**
 * The recomputes among the statements of a call (the upsert; its `FOR SHARE` of the month is a plain select on sqlite).
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function mrbWrites(array $queries): array
{
    return QueryLog::matching($queries, '/^insert into league_month_scores/i');
}

// --- the viewer's row ---------------------------------------------------------------------------------------------

it('reads the viewer\'s row in one statement while it is newer than their rating days, and recomputes when a day changed', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    $learner = mrbLearner();
    mrbDay($learner, '2027-02-02', 30, mrbNow('2027-02-02 10:00:00'));

    // No row yet: the check, the recompute (FOR SHARE + upsert), the read.
    $first = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow())?->points)->toBe(30));
    expect($first)->toHaveCount(4)->and(mrbWrites($first))->toHaveCount(1);

    // The row (written at 12:00 of the 10th) is newer than the day (the 2nd): one read, nothing written, no transaction.
    $fresh = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:05:00'))?->points)->toBe(30));
    expect($fresh)->toHaveCount(1)
        ->and(mrbWrites($fresh))->toBe([])
        ->and($fresh[0])->toContain('league_month_scores')->toContain('learner_score_days');

    // A credit later: the day row is stamped, the next read recomputes and shows the new points.
    DB::table('learner_score_days')->where('learner_id', $learner->id)->update(['month_points' => 45, 'updated_at' => mrbNow('2027-02-10 12:06:00')]);
    $stale = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:07:00'))?->points)->toBe(45));
    expect($stale)->toHaveCount(4)->and(mrbWrites($stale))->toHaveCount(1);

    // ...and is fresh again.
    expect(QueryLog::during(fn () => mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:08:00'))))->toHaveCount(1);
});

it('recomputes when the day was stamped in the same second as the row, and when a day has no stamp', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    $learner = mrbLearner();
    $day = mrbDay($learner, '2027-02-02', 30, mrbNow('2027-02-02 10:00:00'));
    mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:00:00'));

    // A credit in the very second of the row's write: the row may not hold it.
    DB::table('learner_score_days')->where('id', $day->id)->update(['month_points' => 31, 'updated_at' => mrbNow('2027-02-10 12:00:00')]);
    $same = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:00:30'))?->points)->toBe(31));
    expect(mrbWrites($same))->toHaveCount(1);

    // A day written without a stamp (not by the application) is never trusted.
    DB::table('learner_score_days')->where('id', $day->id)->update(['month_points' => 32, 'updated_at' => null]);
    $unstamped = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow('2027-02-10 12:30:00'))?->points)->toBe(32));
    expect(mrbWrites($unstamped))->toHaveCount(1);
});

it('never recomputes for a learner without a rating day in the month: one read, no row', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    $learner = mrbLearner();
    // A day of another month is not this month's.
    mrbDay($learner, '2027-03-02', 30);

    $queries = QueryLog::during(fn () => expect(mrb()->freshScore($month, $learner, mrbNow()))->toBeNull());

    expect($queries)->toHaveCount(1)
        ->and(mrbWrites($queries))->toBe([])
        ->and(LeagueMonthScore::query()->count())->toBe(0);
});

it('keeps the recompute on every read of a v1 month', function (): void {
    config()->set('leagues.scoring.mode', 'off');
    $month = mrb()->currentMonth(mrbNow());
    $learner = mrbLearner();

    expect((int) $month->rules_version)->toBe(1);
    foreach ([1, 2] as $_) {
        $queries = QueryLog::during(fn () => mrb()->freshScore($month, $learner, mrbNow()));
        expect($queries)->toHaveCount(3)->and(mrbWrites($queries))->toHaveCount(1);
    }
});

it('gives the same row as the recompute followed by the read', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    $learner = mrbLearner();
    mrbDay($learner, '2027-02-02', 30, mrbNow('2027-02-02 10:00:00'));
    mrbDay($learner, '2027-02-03', 12, mrbNow('2027-02-03 10:00:00'), long: 2);

    $fresh = mrb()->freshScore($month, $learner, mrbNow());
    $again = mrb()->freshScore($month, $learner, mrbNow('2027-02-10 13:00:00'));
    mrb()->refreshLearner($month, $learner, mrbNow('2027-02-10 13:00:00'));
    $plain = mrb()->scoreOf($month, $learner->id);

    expect($again?->getAttributes())->toBe($plain?->getAttributes())
        ->and($fresh?->getAttributes())->toBe($plain?->getAttributes())
        ->and($again?->points)->toBe(42)
        ->and($again?->active_days)->toBe(2)
        ->and($again?->participant)->toBeTrue();
});

// --- the screen ---------------------------------------------------------------------------------------------------

it('reads GET /league/month in a number of statements that does not grow with the table: 13 on a miss, 4 from the caches (no last month)', function (int $others): void {
    $month = mrb()->currentMonth(mrbNow());
    $me = mrbLearner(['first_name' => 'Me']);
    mrbDay($me, '2027-02-02', 500, mrbNow('2027-02-02 10:00:00'));
    foreach (range(1, $others) as $i) {
        // distinct points around the viewer's: rows above and below with other places
        mrbDay(mrbLearner(), '2027-02-02', 500 + ($i % 2 === 0 ? $i : -$i), mrbNow('2027-02-02 10:00:00'));
    }
    mrb()->refresh($month, mrbNow('2027-02-09 12:00:00'), full: true);
    PureCache::forgetMany(MonthlyRatingService::topKeys($month->id));

    $get = fn () => $this->withHeaders(mrbAuth($me))->getJson('/api/twa/league/month')->assertOk();

    $miss = QueryLog::during(fn () => $get()->assertJsonPath('month.participants', $others + 1)->assertJsonPath('me.points', 500));
    $hit = QueryLog::during(fn () => $get()->assertJsonPath('month.participants', $others + 1)->assertJsonPath('me.points', 500));

    // auth, the month, the viewer's row (1), the participants, the top and its emoji, the own place, the neighbours (4) and their
    // emoji, the last month (its row; no row - no more).
    expect($miss)->toHaveCount(13)
        ->and(mrbWrites($miss))->toBe([])
        // auth, the month, the viewer's row, the last month
        ->and($hit)->toHaveCount(4)
        ->and(QueryLog::matching($hit, '/count\(\*\)/'))->toBe([]);
})->with([10, 60]);

it('counts the participants once per top: cached with it, dropped with it, read as an int from a store that returns a string', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    foreach (range(1, 4) as $i) {
        mrbDay(mrbLearner(), '2027-02-02', 10 * $i, mrbNow('2027-02-02 10:00:00'));
    }
    mrb()->refresh($month, mrbNow(), full: true);

    expect(QueryLog::during(fn () => expect(mrb()->cachedParticipantsCount($month, ScoreCategory::Free))->toBe(4)))->toHaveCount(1)
        ->and(QueryLog::during(fn () => expect(mrb()->cachedParticipantsCount($month, ScoreCategory::Free))->toBe(4)))->toBe([]);

    // A write to the month: the old number is not served.
    mrbDay(mrbLearner(), '2027-02-03', 70, mrbNow('2027-02-03 10:00:00'));
    mrb()->refresh($month, mrbNow('2027-02-10 12:10:00'), full: true);
    expect(mrb()->cachedParticipantsCount($month, ScoreCategory::Free))->toBe(5)
        ->and(mrb()->cachedParticipantsCount($month, ScoreCategory::Pro))->toBe(0);

    // Redis hands a top-level number back as a string (docs/19 §3 п. 11).
    PureCache::store()->put(MonthlyRatingService::countKey($month->id, ScoreCategory::Free), '2', 60);
    expect(mrb()->cachedParticipantsCount($month, ScoreCategory::Free))->toBe(2);

    expect(MonthlyRatingService::countKey(7))->toBe('league:month:count:v1:7:all')
        ->and(MonthlyRatingService::countKey(7, ScoreCategory::Pro))->toBe('league:month:count:v1:7:pro')
        ->and(MonthlyRatingService::topKeys(7))->toContain('league:month:count:v1:7:all', 'league:month:count:v1:7:free', 'league:month:count:v1:7:pro', 'league:month:top:v1:7', 'league:month:top:v2:7:free', 'league:month:top:v2:7:pro');
});

it('drops the cached tops of both tables of a v2 month when its winners are confirmed', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    $free = mrbLearner(['first_name' => 'Free']);
    $pro = mrbLearner(['first_name' => 'Pro']);
    mrbDay($free, '2027-02-02', 30, mrbNow('2027-02-02 10:00:00'));
    mrbDay($pro, '2027-02-02', 40, mrbNow('2027-02-02 10:00:00'), 'pro');
    expect(mrb()->closeMonth($month, mrbNow('2027-03-01 04:00:00')))->toBeTrue();
    $month = LeagueMonth::query()->findOrFail($month->id);

    // Stale values under every key of the month: what a reader would be served if the confirmation left them.
    foreach (MonthlyRatingService::topKeys($month->id) as $key) {
        PureCache::store()->put($key, str_contains($key, ':count:') ? 99 : [], 600);
    }

    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    app(MonthlyRatingReviewService::class)->confirm($month, $admin, now: mrbNow('2027-03-02 10:00:00'));

    foreach (MonthlyRatingService::topKeys($month->id) as $key) {
        expect(PureCache::store()->has($key))->toBeFalse();
    }
    expect(mrb()->top($month, ScoreCategory::Pro))->toHaveCount(1)
        ->and(mrb()->top($month, ScoreCategory::Free))->toHaveCount(1);
});

// --- the neighbours and the gap -----------------------------------------------------------------------------------

it('reads the gap off the rows above exactly as the query gives it, for every row of a table with shared places', function (): void {
    $month = mrb()->currentMonth(mrbNow());
    // points, long words: shared places of two and of four rows, a tie on points broken by the long words, a lone leader
    $tuples = [[90, 0], [70, 2], [70, 0], [70, 0], [50, 1], [50, 1], [50, 1], [50, 1], [50, 0], [20, 0], [20, 0], [5, 0]];
    foreach ($tuples as [$points, $long]) {
        mrbDay(mrbLearner(), '2027-02-02', $points, mrbNow('2027-02-02 10:00:00'), long: $long);
    }
    mrb()->refresh($month, mrbNow(), full: true);
    // `long_words` of the month comes from the journal; here the tie-break column is set directly.
    foreach (LearnerScoreDay::query()->get() as $day) {
        LeagueMonthScore::query()->where('learner_id', $day->learner_id)->update(['long_words' => $day->month_long]);
    }

    $board = app(ScoreBoard::class);
    $scope = BoardScope::month($month->id, ScoreCategory::Free);
    $asked = 0;

    foreach (LeagueMonthScore::query()->orderBy('id')->get() as $score) {
        $expected = $board->gapToNext($scope, $score->points, $score->long_words, $score->active_days);
        $rank = $board->rank($scope, $score->points, $score->long_words, $score->active_days);

        $queries = QueryLog::during(function () use ($month, $score, $rank, $expected, $board, $scope): void {
            $around = mrb()->neighbours($month, $score, 2, $rank);
            $plain = $board->neighbours($scope, new BoardCursor($score->points, $score->long_words, $score->active_days, $score->learner_id), 2);

            expect($around['next_gap'] ?? null)->toBe($expected)
                ->and($around['rank'] ?? null)->toBe($rank)
                // the place given by the caller changes nothing in the lists
                ->and(array_map(fn ($row) => $row->toArray(), $around['above'] ?? []))->toBe(array_map(fn ($row) => $row->toArray(), $plain['above']))
                ->and(array_map(fn ($row) => $row->toArray(), $around['below'] ?? []))->toBe(array_map(fn ($row) => $row->toArray(), $plain['below']));
        });
        // the gap query runs only when both rows above share the viewer's place
        $asked += count(QueryLog::matching($queries, '/select ["`]sc["`]\.["`]points["`] from/'));
    }

    // Only the last two of the four rows that share (50, 1) have two rows of their own place right above them.
    expect($asked)->toBe(2);
});

// --- the emoji of Pro ---------------------------------------------------------------------------------------------

it('finds the emoji of a page with both Pro subqueries narrowed to its ids, and the same learners as the plan names', function (): void {
    $now = CarbonImmutable::now();
    $pro = static function (Learner $learner, string $status = 'active', ?CarbonImmutable $until = null) use ($now): void {
        Subscription::factory()->create([
            'subscriber_type' => 'learner', 'subscriber_id' => $learner->id, 'plan' => 'pro', 'provider' => 'click', 'status' => $status,
            'starts_at' => $now->subDays(10), 'current_period_ends_at' => $until ?? $now->addDays(10),
        ]);
    };

    $own = mrbLearner(['pro_emoji' => 'fire']);
    $pro($own);
    $ended = mrbLearner(['pro_emoji' => 'star']);
    $pro($ended, 'expired', $now->subDay());
    $free = mrbLearner(['pro_emoji' => 'owl']);
    $noChoice = mrbLearner();
    $pro($noChoice);
    $outside = mrbLearner(['pro_emoji' => 'gem']); // Pro with an emoji, but not on the page
    $pro($outside);

    $organization = Organization::factory()->create(['status' => 'active']);
    Subscription::factory()->create([
        'subscriber_type' => 'organization', 'subscriber_id' => $organization->id, 'plan' => 'pro', 'provider' => 'click', 'status' => 'active',
        'starts_at' => $now->subDays(10), 'current_period_ends_at' => $now->addDays(10),
    ]);
    $seated = mrbLearner(['pro_emoji' => 'crown']);
    OrganizationMember::factory()->create(['organization_id' => $organization->id, 'learner_id' => $seated->id]);

    $page = [$own->id, $ended->id, $free->id, $noChoice->id, $seated->id];
    $emoji = [];
    $queries = QueryLog::during(function () use (&$emoji, $page): void {
        $emoji = app(ProEmojiService::class)->visibleForMany($page);
    });

    expect($emoji)->toBe([$own->id => '🔥', $seated->id => '👑'])
        ->and($queries)->toHaveCount(1)
        ->and($queries[0])->toMatch('/["`]subscriptions["`]\.["`]subscriber_id["`] in \(\?(, \?){4}\)/')
        ->and($queries[0])->toMatch('/["`]om["`]\.["`]learner_id["`] in \(\?(, \?){4}\)/');

    // The same learners the plan resolver calls Pro.
    $resolver = app(EntitlementResolver::class);
    foreach ([$own, $ended, $free, $noChoice, $seated] as $learner) {
        $shown = app(ProEmojiService::class)->visible($learner, $resolver->resolve($learner, $now), $now);
        expect($emoji[$learner->id] ?? null)->toBe($shown);
    }

    expect(app(ProEmojiService::class)->visibleForMany([$outside->id]))->toBe([$outside->id => '💎'])
        ->and(QueryLog::during(fn () => app(ProEmojiService::class)->visibleForMany([])))->toBe([]);
});
