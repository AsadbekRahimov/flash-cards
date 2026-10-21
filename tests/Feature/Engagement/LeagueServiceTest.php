<?php

declare(strict_types=1);

/*
 * Leagues (docs/13 §1.5, §2.4, §7.3): week boundaries in the league time zone, placement rules,
 * the points formula over learner_daily_stats, the standings cache, the idempotent close with
 * promotions / demotions, and the after-commit hook of the review path.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Jobs\SendLeagueResultJob;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** Wednesday 2026-10-21 10:00 in Tashkent (05:00 UTC); the week is 2026-10-19 … 2026-10-25. */
function lgNow(string $time = '2026-10-21 05:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function lgService(): LeagueService
{
    return app(LeagueService::class);
}

/** A day row of a learner (Tashkent-local date). */
function lgDay(Learner $learner, string $date, int $reviews = 0, int $correct = 0, int $new = 0, bool $goalMet = false): LearnerDailyStats
{
    return LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => $date,
        'reviews_count' => $reviews,
        'correct_count' => $correct,
        'new_words_count' => $new,
        'goal_met_at' => $goalMet ? lgNow() : null,
    ]);
}

beforeEach(function (): void {
    config(['leagues.timezone' => 'Asia/Tashkent', 'leagues.capacity' => 30, 'leagues.promote' => 5, 'leagues.demote' => 5]);
    $this->travelTo(lgNow());
});

// --- weeks -------------------------------------------------------------------------------

it('keys the week by the Monday in the league time zone, one row per week', function (): void {
    // Sunday 2026-10-18 23:30 Tashkent = 18:30 UTC → still the week of 2026-10-12.
    $sunday = lgService()->currentWeek(lgNow('2026-10-18 18:30:00'));
    // Monday 2026-10-19 00:30 Tashkent = Sunday 19:30 UTC → the week of 2026-10-19.
    $monday = lgService()->currentWeek(lgNow('2026-10-18 19:30:00'));
    $again = lgService()->currentWeek(lgNow('2026-10-25 18:59:59'));

    expect($sunday->starts_on->toDateString())->toBe('2026-10-12')
        ->and($sunday->ends_on->toDateString())->toBe('2026-10-18')
        ->and($monday->starts_on->toDateString())->toBe('2026-10-19')
        ->and($monday->ends_on->toDateString())->toBe('2026-10-25')
        ->and($monday->status)->toBe('open')
        ->and($again->id)->toBe($monday->id)
        ->and(LeagueWeek::query()->count())->toBe(2)
        ->and(lgService()->findWeek(lgNow('2026-10-26 10:00:00')))->toBeNull();
});

// --- placement ----------------------------------------------------------------------------

it('places a newcomer in tier 0 of the current week once, whatever how often it is asked', function (): void {
    $learner = Learner::factory()->create();

    $first = lgService()->place($learner, lgNow());
    $second = lgService()->place($learner, lgNow('2026-10-23 05:00:00'));

    expect($first)->not->toBeNull()
        ->and($second?->id)->toBe($first?->id)
        ->and($first?->tier_before)->toBe(0)
        ->and($first?->week?->starts_on->toDateString())->toBe('2026-10-19')
        ->and(LeagueMember::query()->count())->toBe(1)
        ->and(LeagueGroup::query()->sole()->members_count)->toBe(1)
        ->and(LeagueGroup::query()->sole()->tier)->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeaguePlaced->value)->count())->toBe(1);
});

it('opens a new group of the tier only when every existing one is full', function (): void {
    config(['leagues.capacity' => 3]);

    foreach (Learner::factory()->count(7)->create() as $learner) {
        lgService()->place($learner, lgNow());
    }

    $groups = LeagueGroup::query()->orderBy('id')->get();
    expect($groups->pluck('members_count')->all())->toBe([3, 3, 1])
        ->and($groups->pluck('capacity')->unique()->all())->toBe([3])
        ->and(LeagueMember::query()->count())->toBe(7)
        ->and(LeagueMember::query()->distinct('learner_id')->count('learner_id'))->toBe(7);
});

it('does not place an opted-out, blocked or league-less learner', function (): void {
    $optedOut = Learner::factory()->create(['meta' => [LeagueService::OPT_OUT_META => true]]);
    $blocked = Learner::factory()->create(['blocked_at' => lgNow()]);

    expect(lgService()->place($optedOut, lgNow()))->toBeNull()
        ->and(lgService()->place($blocked, lgNow()))->toBeNull();

    config(['leagues.enabled' => false]);
    expect(lgService()->place(Learner::factory()->create(), lgNow()))->toBeNull()
        ->and(LeagueMember::query()->count())->toBe(0)
        ->and(LeagueWeek::query()->count())->toBe(0);
});

it('takes the tier of the next week from the last closed result, bounded to the tiers', function (): void {
    $closedWeek = LeagueWeek::factory()->startingOn('2026-10-12')->closed()->create();
    $promoted = Learner::factory()->create();
    $demoted = Learner::factory()->create();
    $stayedTop = Learner::factory()->create();
    LeagueMember::factory()->closed('promoted', 1, 300)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 2])->id,
        'learner_id' => $promoted->id,
    ]);
    LeagueMember::factory()->closed('demoted', 30, 1)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 1])->id,
        'learner_id' => $demoted->id,
    ]);
    LeagueMember::factory()->closed('stayed', 10, 50)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 4])->id,
        'learner_id' => $stayedTop->id,
    ]);
    // A membership of an earlier week that nobody closed yet is closed by the placement itself (see below).
    $pending = Learner::factory()->create();
    LeagueMember::factory()->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => LeagueWeek::factory()->startingOn('2026-10-05')->create()->id, 'tier' => 3])->id,
        'learner_id' => $pending->id,
    ]);

    expect(lgService()->place($promoted, lgNow())?->tier_before)->toBe(3)
        ->and(lgService()->place($demoted, lgNow())?->tier_before)->toBe(0)
        ->and(lgService()->place($stayedTop, lgNow())?->tier_before)->toBe(4)
        ->and(lgService()->place($pending, lgNow())?->tier_before)->toBe(4) // alone in tier 3: rank 1, promoted
        ->and(LeagueGroup::query()->where('league_week_id', lgService()->findWeek(lgNow())?->id)->pluck('tier')->sort()->values()->all())->toBe([0, 3, 4]); // the 'pending' one shares the top-tier group
});

it('is placed by the first grade of a day after the review transaction commits — never inside it', function (): void {
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 20]);
    $stats = app(DailyStatsService::class);

    DB::transaction(function () use ($learner, $stats): void {
        $stats->recordReview($learner, 4, 500, false, lgNow());
        // Still inside the review transaction: no league rows exist.
        expect(LeagueMember::query()->count())->toBe(0)
            ->and(LeagueWeek::query()->count())->toBe(0);
    });

    expect(LeagueMember::query()->where('learner_id', $learner->id)->count())->toBe(1);

    // The second grade of the day does not even look (no second week row, no second membership).
    DB::transaction(fn () => $stats->recordReview($learner, 4, 500, false, lgNow()->addMinute()));
    expect(LeagueMember::query()->count())->toBe(1)
        ->and(LeagueWeek::query()->count())->toBe(1);

    // A learner who never graded a card is nowhere.
    $idle = Learner::factory()->create();
    expect(lgService()->membership($idle, lgService()->currentWeek(lgNow())))->toBeNull();
});

it('does not fail the grade when the placement throws after commit', function (): void {
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
    // No league tables at all: every placement query fails after the commit.
    Schema::drop('league_members');
    Schema::drop('league_groups');
    Schema::drop('league_weeks');
    Exceptions::fake();

    $row = DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, lgNow()));

    // The grade itself is committed and returned; the league error is only reported. (No further
    // queries here: on PostgreSQL the failed statement aborted the test's outer transaction.)
    expect($row->reviews_count)->toBe(1);
    Exceptions::assertReported(QueryException::class);
});

// --- standings ------------------------------------------------------------------------------

it('sums the points of the week from learner_daily_stats with the configured weights, both boundary days inclusive', function (): void {
    config(['leagues.points' => ['review' => 1, 'correct' => 1, 'new_word' => 2, 'goal_met' => 10]]);
    $a = Learner::factory()->create(['first_name' => 'Aziz']);
    $b = Learner::factory()->create(['first_name' => 'Bek']);
    $c = Learner::factory()->create(['first_name' => '']);
    lgDay($a, '2026-10-19', reviews: 10, correct: 8, new: 3, goalMet: true);  // 10 + 8 + 6 + 10 = 34 (Monday)
    lgDay($a, '2026-10-25', reviews: 5, correct: 5, new: 0);                   // 10 (Sunday)
    lgDay($a, '2026-10-18', reviews: 100, correct: 100, new: 100, goalMet: true); // last week: ignored
    lgDay($a, '2026-10-26', reviews: 100, correct: 100, new: 100, goalMet: true); // next week: ignored
    lgDay($b, '2026-10-21', reviews: 20, correct: 20, new: 10, goalMet: true);  // 20 + 20 + 20 + 10 = 70
    lgDay($b, '2026-10-22', reviews: 1, correct: 0, new: 0);                   // 1

    $ma = lgService()->place($a, lgNow());
    $mb = lgService()->place($b, lgNow());
    $mc = lgService()->place($c, lgNow());
    $group = LeagueGroup::query()->sole();

    $standings = lgService()->standings($group, lgNow());

    expect($standings->map(fn ($s): array => [$s->rank, $s->memberId, $s->name, $s->points])->all())->toBe([
        [1, $mb?->id, 'Bek', 71],
        [2, $ma?->id, 'Aziz', 44],
        [3, $mc?->id, null, 0],
    ]);

    // Same sum, computed by hand from the day rows of the week.
    $byHand = LearnerDailyStats::query()
        ->where('learner_id', $a->id)
        ->whereBetween('date', [lgService()->currentWeek(lgNow())->starts_on, lgService()->currentWeek(lgNow())->ends_on])
        ->get()
        ->sum(fn (LearnerDailyStats $d): int => $d->reviews_count + $d->correct_count + 2 * $d->new_words_count + ($d->goal_met_at !== null ? 10 : 0));
    expect($byHand)->toBe(44);
});

it('breaks ties by the earlier placement and serves a group from the cache until it expires', function (): void {
    $a = Learner::factory()->create();
    $b = Learner::factory()->create();
    lgDay($a, '2026-10-21', reviews: 5);
    lgDay($b, '2026-10-21', reviews: 5);
    $mb = lgService()->place($b, lgNow());
    $ma = lgService()->place($a, lgNow());
    $group = LeagueGroup::query()->sole();

    $first = lgService()->standings($group, lgNow());
    expect($first->pluck('memberId')->all())->toBe([$mb?->id, $ma?->id]);

    // New points land, the cached standings do not move until the TTL passes.
    LearnerDailyStats::query()->where('learner_id', $a->id)->update(['reviews_count' => 50]);
    expect(lgService()->standings($group, lgNow())->first()?->memberId)->toBe($mb?->id)
        ->and(Cache::has(LeagueService::CACHE_PREFIX.$group->id))->toBeTrue();

    // The TTL is the cache store's own clock (Redis in CI ignores time travel): once the entry is gone,
    // the next read recomputes and the new leader shows.
    Cache::forget(LeagueService::CACHE_PREFIX.$group->id);
    expect(lgService()->standings($group, lgNow())->first()?->memberId)->toBe($ma?->id);
});

it('shows the Today line and the overview with place, points, zone and what is missing', function (): void {
    config(['leagues.promote' => 2, 'leagues.demote' => 2]);
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1]);
    $learners = Learner::factory()->count(6)->sequence(fn ($seq): array => ['first_name' => 'L'.$seq->index])->create();
    foreach ($learners as $i => $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
        lgDay($learner, '2026-10-20', reviews: 60 - $i * 10); // 60, 50, 40, 30, 20, 10
    }

    $third = lgService()->overview($learners[2], lgNow());
    $fifth = lgService()->overview($learners[4], lgNow());
    $top = lgService()->overview($learners[0], lgNow());
    $line = lgService()->todayLine($learners[2], lgNow());

    expect($third->membership?->rank)->toBe(3)
        ->and($third->membership?->zone)->toBe('safe')
        ->and($third->membership?->pointsToPromote)->toBe(11)   // 50 - 40 + 1
        ->and($third->membership?->pointsToSafety)->toBeNull()
        ->and($fifth->membership?->zone)->toBe('demotion')
        ->and($fifth->membership?->pointsToSafety)->toBe(11)    // 30 - 20 + 1 (4th place, 30 points, is the last safe one)
        ->and($fifth->membership?->pointsToPromote)->toBe(31)
        ->and($top->membership?->zone)->toBe('promotion')
        ->and($top->membership?->pointsToPromote)->toBeNull()
        ->and($third->membership?->membersCount)->toBe(6)
        ->and($third->membership?->tierKey)->toBe('silver')
        ->and(array_map(fn ($s): ?string => $s->name, $third->membership?->standings ?? []))->toBe(['L0', 'L1', 'L2', 'L3', 'L4', 'L5'])
        ->and($line?->toArray())->toBe(['tier' => 1, 'tier_key' => 'silver', 'rank' => 3, 'points' => 40, 'members_count' => 6])
        ->and(lgService()->todayLine(Learner::factory()->create(), lgNow()))->toBeNull()
        ->and($third->weekStartsOn)->toBe('2026-10-19')
        ->and($third->closesAt)->toBe('2026-10-25T19:00:00+00:00');
});

// --- closing ---------------------------------------------------------------------------------

/** @return array{week: LeagueWeek, groups: array<int, LeagueGroup>, learners: array<int, list<Learner>>} */
function lgWeekToClose(): array
{
    config(['leagues.promote' => 2, 'leagues.demote' => 2]);
    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $groups = [];
    $learners = [];
    foreach ([0, 2, 4] as $tier) {
        $groups[$tier] = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $tier]);
        $learners[$tier] = [];
        foreach (range(0, 5) as $i) {
            $learner = Learner::factory()->create(['first_name' => "T{$tier}-{$i}"]);
            LeagueMember::factory()->create(['league_group_id' => $groups[$tier]->id, 'learner_id' => $learner->id]);
            lgDay($learner, '2026-10-14', reviews: 60 - $i * 10);
            $learners[$tier][] = $learner;
        }
    }

    return ['week' => $week, 'groups' => $groups, 'learners' => $learners];
}

it('closes a week once: final ranks and points, top-N promoted, bottom-N demoted, tier 0 never down, top tier never up', function (): void {
    Queue::fake();
    ['week' => $week, 'groups' => $groups] = lgWeekToClose();
    $now = lgNow('2026-10-18 19:05:00'); // Monday 00:05 Tashkent

    expect(lgService()->closeWeek($week, $now))->toBeTrue()
        ->and($week->fresh()?->status)->toBe('closed')
        ->and($week->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00');

    $results = fn (int $tier): array => LeagueMember::query()
        ->where('league_group_id', $groups[$tier]->id)
        ->orderBy('final_rank')
        ->get()
        ->map(fn (LeagueMember $m): string => "{$m->final_rank}:{$m->final_points}:{$m->result}")
        ->all();

    expect($results(0))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:stayed', '6:10:stayed'])
        ->and($results(2))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and($results(4))->toBe(['1:60:stayed', '2:50:stayed', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted']);

    Queue::assertPushed(SendLeagueResultJob::class, 18);
    expect(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);

    // Again: nothing changes, nothing is sent again.
    LearnerDailyStats::query()->update(['reviews_count' => 999]);
    expect(lgService()->closeWeek($week->fresh() ?? $week, $now->addHour()))->toBeFalse()
        ->and($results(2))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and($week->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00');
    Queue::assertPushed(SendLeagueResultJob::class, 18);
    expect(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);

    // Closed standings come from the final columns (no cache, no recomputation).
    $closed = lgService()->standings($groups[2], $now->addDay());
    expect($closed->map(fn ($s): array => [$s->rank, $s->points])->all())->toBe([[1, 60], [2, 50], [3, 40], [4, 30], [5, 20], [6, 10]]);
});

it('promotes a whole small group rather than demoting anyone who is also in the top-N', function (): void {
    Queue::fake();
    config(['leagues.promote' => 5, 'leagues.demote' => 5]);
    $week = LeagueWeek::factory()->startingOn('2026-10-12')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 2]);
    foreach (range(1, 3) as $i) {
        $learner = Learner::factory()->create();
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
        lgDay($learner, '2026-10-14', reviews: $i);
    }

    lgService()->closeWeek($week, lgNow('2026-10-18 19:05:00'));

    expect(LeagueMember::query()->pluck('result')->unique()->all())->toBe(['promoted']);
});

it('closes only the weeks that ended, from the scheduler command, and never twice', function (): void {
    Queue::fake();
    ['week' => $old] = lgWeekToClose();
    $older = LeagueWeek::factory()->startingOn('2026-10-05')->create();
    $current = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    LeagueMember::factory()->create(['league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $current->id])->id]);

    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 2')->assertSuccessful();

    expect($old->fresh()?->status)->toBe('closed')
        ->and($older->fresh()?->status)->toBe('closed')
        ->and($current->fresh()?->status)->toBe('open')
        ->and(LeagueMember::query()->where('league_week_id', $current->id)->whereNotNull('result')->count())->toBe(0);
    Queue::assertPushed(SendLeagueResultJob::class, 18);

    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    Queue::assertPushed(SendLeagueResultJob::class, 18);

    config(['leagues.enabled' => false]);
    $this->travelTo(lgNow('2026-10-26 10:00:00'));
    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    expect($current->fresh()?->status)->toBe('open');
});

it('places a learner next week by the result of the closed one', function (): void {
    Queue::fake();
    ['week' => $week, 'learners' => $learners] = lgWeekToClose();
    lgService()->closeWeek($week, lgNow('2026-10-18 19:05:00'));

    expect(lgService()->place($learners[2][0], lgNow())?->tier_before)->toBe(3)  // promoted from 2
        ->and(lgService()->place($learners[2][5], lgNow())?->tier_before)->toBe(1) // demoted from 2
        ->and(lgService()->place($learners[2][2], lgNow())?->tier_before)->toBe(2) // stayed
        ->and(lgService()->place($learners[0][5], lgNow())?->tier_before)->toBe(0) // tier 0 cannot fall
        ->and(lgService()->place($learners[4][0], lgNow())?->tier_before)->toBe(4) // top tier cannot rise
        ->and(lgService()->overview($learners[2][0], lgNow())->lastResult?->result)->toBe('promoted');
});

it('closes the weeks that ended but were not closed yet before it places, so last week\'s result decides the tier', function (): void {
    Queue::fake();
    // Monday 00:03 in Tashkent: leagues:close-week (00:05) has not run — or was missed.
    $monday = lgNow('2026-10-18 19:03:00');
    $this->travelTo($monday);
    ['week' => $week, 'learners' => $learners] = lgWeekToClose();
    expect($week->fresh()?->status)->not->toBe('closed');

    $member = lgService()->place($learners[2][0], $monday); // first grade of the week; rank 1 of tier 2

    expect($member?->tier_before)->toBe(3)
        ->and($week->fresh()?->status)->toBe('closed')
        ->and($week->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:03:00');
    Queue::assertPushed(SendLeagueResultJob::class, 18);

    // The demoted one is placed one tier lower, and the late scheduler run changes and sends nothing.
    expect(lgService()->place($learners[2][5], $monday)?->tier_before)->toBe(1);
    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    Queue::assertPushed(SendLeagueResultJob::class, 18);
    expect(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);
});

it('queues no result DM for a banned, DM-closed, opted-out or deleted member', function (): void {
    Queue::fake();
    ['week' => $week, 'learners' => $learners] = lgWeekToClose();
    $learners[0][0]->forceFill(['blocked_at' => now()])->save();
    $learners[0][1]->forceFill(['dm_blocked_at' => now()])->save();
    $learners[0][2]->forceFill(['meta' => [LeagueService::OPT_OUT_META => true]])->save();
    $learners[0][3]->delete();

    lgService()->closeWeek($week, lgNow('2026-10-18 19:05:00'));

    Queue::assertPushed(SendLeagueResultJob::class, 14);
    // Their results are still written.
    expect(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0);
});
