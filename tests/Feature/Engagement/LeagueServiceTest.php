<?php

declare(strict_types=1);

/*
 * Leagues (docs/13 §1.5, §2.4, §7.3): week boundaries in the league time zone, placement rules,
 * the points formula over learner_daily_stats, the standings cache, the close by groups (one
 * statement per group, idempotent, resumable) with promotions / demotions, the result DMs in
 * batches, and the after-commit hook of the review path that never closes a week itself.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Jobs\SendLeagueResultsJob;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
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

/**
 * Member ids of every result-DM batch queued so far, in queue order.
 *
 * @return list<int>
 */
function lgQueuedMemberIds(): array
{
    return Queue::pushed(SendLeagueResultsJob::class)->flatMap(fn (SendLeagueResultsJob $job): array => $job->memberIds)->values()->all();
}

/** @return list<string> `rank:points:result` of a group, best first */
function lgResults(LeagueGroup $group): array
{
    return LeagueMember::query()
        ->where('league_group_id', $group->id)
        ->orderBy('final_rank')
        ->orderBy('id')
        ->get()
        ->map(fn (LeagueMember $m): string => "{$m->final_rank}:{$m->final_points}:{$m->result}")
        ->all();
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
        ->and(lgService()->findWeek(lgNow('2026-10-26 10:00:00')))->toBeNull()
        // The week of 10-12 ended at Monday 00:00 Tashkent (Sunday 19:00 UTC) — not a second earlier.
        ->and(lgService()->hasEnded($sunday, lgNow('2026-10-18 18:59:59')))->toBeFalse()
        ->and(lgService()->hasEnded($sunday, lgNow('2026-10-18 19:00:00')))->toBeTrue()
        ->and(lgService()->hasEnded($monday, lgNow('2026-10-25 18:59:59')))->toBeFalse();
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
        ->and(LeagueGroup::query()->sole()->closed_at)->toBeNull()
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
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 2, 'closed_at' => now()])->id,
        'learner_id' => $promoted->id,
    ]);
    LeagueMember::factory()->closed('demoted', 30, 1)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 1, 'closed_at' => now()])->id,
        'learner_id' => $demoted->id,
    ]);
    LeagueMember::factory()->closed('stayed', 10, 50)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $closedWeek->id, 'tier' => 4, 'closed_at' => now()])->id,
        'learner_id' => $stayedTop->id,
    ]);
    // A membership of an earlier week that nobody closed yet: the placement finalises that one group itself (see below).
    $pending = Learner::factory()->create();
    $pendingGroup = LeagueGroup::factory()->create(['league_week_id' => LeagueWeek::factory()->startingOn('2026-10-05')->create()->id, 'tier' => 3]);
    LeagueMember::factory()->create(['league_group_id' => $pendingGroup->id, 'learner_id' => $pending->id]);

    expect(lgService()->place($promoted, lgNow())?->tier_before)->toBe(3)
        ->and(lgService()->place($demoted, lgNow())?->tier_before)->toBe(0)
        ->and(lgService()->place($stayedTop, lgNow())?->tier_before)->toBe(4)
        ->and(lgService()->place($pending, lgNow())?->tier_before)->toBe(4) // alone in tier 3: rank 1, promoted
        ->and($pendingGroup->fresh()?->closed_at)->not->toBeNull()
        ->and(LeagueWeek::query()->where('starts_on', (new LeagueWeek)->fromDateTime('2026-10-05'))->sole()->status)->toBe('open') // the group, not the week
        ->and(LeagueGroup::query()->where('league_week_id', lgService()->findWeek(lgNow())?->id)->pluck('tier')->sort()->values()->all())->toBe([0, 3, 4]); // the 'pending' one shares the top-tier group
});

it('takes the verdict of the latest week by date, not by row order, and keeps the tier over a skipped week', function (): void {
    $learner = Learner::factory()->create();
    // Rows created newest-week-first: ids would pick the wrong one.
    $later = LeagueWeek::factory()->startingOn('2026-10-12')->closed()->create();
    $earlier = LeagueWeek::factory()->startingOn('2026-10-05')->closed()->create();
    LeagueMember::factory()->closed('demoted', 30, 1)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $earlier->id, 'tier' => 3, 'closed_at' => now()])->id,
        'learner_id' => $learner->id,
    ]);
    LeagueMember::factory()->closed('promoted', 1, 300)->create([
        'league_group_id' => LeagueGroup::factory()->create(['league_week_id' => $later->id, 'tier' => 2, 'closed_at' => now()])->id,
        'learner_id' => $learner->id,
    ]);

    expect(lgService()->tierFor($learner, lgService()->currentWeek(lgNow()), lgNow()))->toBe(3);

    // Two weeks later, nothing in between: still the same verdict.
    $this->travelTo(lgNow('2026-11-04 05:00:00'));
    expect(lgService()->place($learner, lgNow('2026-11-04 05:00:00'))?->tier_before)->toBe(3);
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

it('seats nobody in a week that is already closing or closed', function (): void {
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create(['status' => 'closing']);
    $learner = Learner::factory()->create();

    expect(lgService()->place($learner, lgNow()))->toBeNull()
        ->and(LeagueMember::query()->count())->toBe(0)
        ->and(LeagueGroup::query()->count())->toBe(0);

    $week->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    expect(lgService()->place($learner, lgNow()))->toBeNull()
        ->and(LeagueMember::query()->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeaguePlaced->value)->count())->toBe(0);
});

it('never seats anyone in a finalised group, even while its week is still open', function (): void {
    config(['leagues.capacity' => 3]);
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $stamped = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 0, 'members_count' => 1, 'closed_at' => now()]);
    LeagueMember::factory()->create(['league_group_id' => $stamped->id]);

    $member = lgService()->place(Learner::factory()->create(), lgNow());

    expect($member?->league_group_id)->not->toBe($stamped->id)
        ->and(LeagueGroup::query()->count())->toBe(2)
        ->and($stamped->fresh()?->members_count)->toBe(2); // the fixture's own bump; nothing from the service
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
            $learner = Learner::factory()->create(['first_name' => "T{$tier}-{$i}", 'timezone' => 'Asia/Tashkent']);
            LeagueMember::factory()->create(['league_group_id' => $groups[$tier]->id, 'learner_id' => $learner->id]);
            lgDay($learner, '2026-10-14', reviews: 60 - $i * 10);
            $learners[$tier][] = $learner;
        }
    }

    return ['week' => $week, 'groups' => $groups, 'learners' => $learners];
}

it('closes a week once: final ranks and points, top-N promoted, bottom-N demoted, tier 0 never down, top tier never up, DMs in batches', function (): void {
    Queue::fake();
    config(['leagues.notify_batch_size' => 5]);
    ['week' => $week, 'groups' => $groups] = lgWeekToClose();
    $now = lgNow('2026-10-18 19:05:00'); // Monday 00:05 Tashkent

    expect(lgService()->closeWeek($week, $now))->toBeTrue()
        ->and($week->fresh()?->status)->toBe('closed')
        ->and($week->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and(LeagueGroup::query()->where('league_week_id', $week->id)->whereNull('closed_at')->count())->toBe(0)
        ->and($groups[0]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->where('updated_at', '<', $now)->count())->toBe(0);

    expect(lgResults($groups[0]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:stayed', '6:10:stayed'])
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and(lgResults($groups[4]))->toBe(['1:60:stayed', '2:50:stayed', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted']);

    // 18 members, batches of 5: four jobs carrying every member once, in id order.
    $allIds = LeagueMember::query()->where('league_week_id', $week->id)->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    Queue::assertPushed(SendLeagueResultsJob::class, 4);
    expect(Queue::pushed(SendLeagueResultsJob::class)->map(fn (SendLeagueResultsJob $j): int => count($j->memberIds))->all())->toBe([5, 5, 5, 3])
        ->and(lgQueuedMemberIds())->toBe($allIds)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->sole()->props)->toMatchArray(['week_id' => $week->id, 'groups' => 3, 'members' => 18]);

    // Again: nothing changes, nothing is sent again.
    LearnerDailyStats::query()->update(['reviews_count' => 999]);
    expect(lgService()->closeWeek($week->fresh() ?? $week, $now->addHour()))->toBeFalse()
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and($week->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00');
    Queue::assertPushed(SendLeagueResultsJob::class, 4);
    expect(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);

    // Closed standings come from the final columns (no cache, no recomputation).
    $closed = lgService()->standings($groups[2], $now->addDay());
    expect($closed->map(fn ($s): array => [$s->rank, $s->points])->all())->toBe([[1, 60], [2, 50], [3, 40], [4, 30], [5, 20], [6, 10]])
        ->and(Cache::has(LeagueService::CACHE_PREFIX.$groups[2]->id))->toBeFalse();
});

it('writes the final columns of a group in ONE statement', function (): void {
    Queue::fake();
    ['week' => $week, 'groups' => $groups] = lgWeekToClose();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')) {
            $statements[] = $query->sql;
        }
    });

    expect(lgService()->closeGroup($groups[2], lgNow('2026-10-18 19:05:00')))->toBeTrue();

    $memberUpdates = array_values(array_filter($statements, fn (string $sql): bool => str_contains($sql, 'league_members')));
    expect($memberUpdates)->toHaveCount(1)
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and($groups[2]->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00') // the caller's model is refreshed too
        ->and(lgService()->closeGroup($groups[2], lgNow('2026-10-18 20:00:00')))->toBeFalse()
        ->and($groups[2]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00');
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

it('refuses to close a week that has not ended, and finalises none of its groups', function (): void {
    Queue::fake();
    $week = LeagueWeek::factory()->startingOn('2026-10-19')->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1]);
    LeagueMember::factory()->create(['league_group_id' => $group->id]);

    // Sunday 23:59:59 Tashkent: the week is still running.
    expect(lgService()->closeWeek($week, lgNow('2026-10-25 18:59:59')))->toBeFalse()
        ->and(lgService()->closeGroup($group, lgNow('2026-10-25 18:59:59')))->toBeFalse()
        ->and($week->fresh()?->status)->toBe('open')
        ->and($group->fresh()?->closed_at)->toBeNull()
        ->and(LeagueMember::query()->whereNotNull('result')->count())->toBe(0)
        ->and(lgService()->closeDue(lgNow('2026-10-25 18:59:59')))->toBe(0);
    Queue::assertNothingPushed();

    // One second later it has.
    expect(lgService()->closeDue(lgNow('2026-10-25 19:00:00')))->toBe(1)
        ->and($week->fresh()?->status)->toBe('closed');
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
        ->and(LeagueMember::query()->where('league_week_id', $current->id)->whereNotNull('result')->count())->toBe(0)
        ->and(lgQueuedMemberIds())->toHaveCount(18);

    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    expect(lgQueuedMemberIds())->toHaveCount(18);

    config(['leagues.enabled' => false]);
    $this->travelTo(lgNow('2026-10-26 10:00:00'));
    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    expect($current->fresh()?->status)->toBe('open');
});

it('runs the close at 00:00 in the league zone with a 00:05 safety run on the same mutex', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'leagues:close-week'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0,5 0 * * *')
        ->and($event->timezone)->toBe('Asia/Tashkent')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30)
        ->and(collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'leagues:close-week'))->count())->toBe(1);
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

it('finalises only the learner\'s own group when the first grade of the week comes before the close: right tier, no week closed, no DM yet', function (): void {
    Queue::fake();
    // Monday 00:03 in Tashkent: leagues:close-week (00:00) has not run — or was missed.
    $monday = lgNow('2026-10-18 19:03:00');
    $this->travelTo($monday);
    ['week' => $week, 'groups' => $groups, 'learners' => $learners] = lgWeekToClose();

    // The first grade of the week, through the review path (after commit): rank 1 of tier 2.
    DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learners[2][0], 4, 500, false, $monday));
    $member = lgService()->membership($learners[2][0], lgService()->currentWeek($monday));

    expect($member?->tier_before)->toBe(3)
        ->and($week->fresh()?->status)->toBe('open')
        ->and($groups[2]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:03:00')
        ->and($groups[0]->fresh()?->closed_at)->toBeNull()
        ->and($groups[4]->fresh()?->closed_at)->toBeNull()
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNotNull('result')->count())->toBe(6)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(0)
        // The verdict is already visible to the group, final columns, while the week is open.
        ->and(lgService()->overview($learners[2][5], $monday)->lastResult?->result)->toBe('demoted')
        ->and(lgService()->standings($groups[2]->fresh() ?? $groups[2], $monday)->map(fn ($s): array => [$s->rank, $s->points])->all())->toBe([[1, 60], [2, 50], [3, 40], [4, 30], [5, 20], [6, 10]])
        ->and(Cache::has(LeagueService::CACHE_PREFIX.$groups[2]->id))->toBeFalse();
    Queue::assertNothingPushed();

    // The demoted one is placed one tier lower without a second finalisation; points that land late do not move the verdict.
    LearnerDailyStats::query()->where('learner_id', $learners[2][5]->id)->update(['reviews_count' => 999]);
    expect(lgService()->place($learners[2][5], $monday)?->tier_before)->toBe(1)
        ->and($groups[2]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:03:00')
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted']);

    // The late scheduler run finishes the other two groups, keeps this one as it is, and mails everybody once.
    $this->travelTo(lgNow('2026-10-18 19:05:00'));
    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 1')->assertSuccessful();
    expect($week->fresh()?->status)->toBe('closed')
        ->and($groups[2]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:03:00')
        ->and($groups[0]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and(lgResults($groups[2]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:demoted', '6:10:demoted'])
        ->and(lgResults($groups[0]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:stayed', '6:10:stayed'])
        ->and(lgQueuedMemberIds())->toHaveCount(18)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);

    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 0')->assertSuccessful();
    expect(lgQueuedMemberIds())->toHaveCount(18);
});

it('catches up a scheduler that missed the whole Monday: placements meanwhile got their tiers, the late run mails everyone once', function (): void {
    Queue::fake();
    ['week' => $week, 'groups' => $groups, 'learners' => $learners] = lgWeekToClose();

    // Tuesday: two learners of two groups came back before any close ran.
    $tuesday = lgNow('2026-10-20 05:00:00');
    $this->travelTo($tuesday);
    expect(lgService()->place($learners[0][0], $tuesday)?->tier_before)->toBe(1)
        ->and(lgService()->place($learners[4][5], $tuesday)?->tier_before)->toBe(3)
        ->and($groups[0]->fresh()?->closed_at)->not->toBeNull()
        ->and($groups[4]->fresh()?->closed_at)->not->toBeNull()
        ->and($groups[2]->fresh()?->closed_at)->toBeNull()
        ->and($week->fresh()?->status)->toBe('open');
    Queue::assertNothingPushed();

    // Wednesday night the scheduler is back.
    $this->travelTo(lgNow('2026-10-20 19:00:00'));
    $this->artisan('leagues:close-week')->expectsOutput('League weeks closed: 1')->assertSuccessful();
    expect($week->fresh()?->status)->toBe('closed')
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0)
        ->and(lgQueuedMemberIds())->toHaveCount(18)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->where('result', 'promoted')->count())->toBe(4)
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->where('result', 'demoted')->count())->toBe(4);
});

it('resumes a close that died half-way: a closing week with stamped groups is finished, nothing is written or sent twice', function (): void {
    Queue::fake();
    ['week' => $week, 'groups' => $groups] = lgWeekToClose();
    $now = lgNow('2026-10-18 19:05:00');

    // The crashed run got as far as the barrier and the first group.
    $week->forceFill(['status' => 'closing'])->save();
    expect(lgService()->closeGroup($groups[0], lgNow('2026-10-18 19:00:30')))->toBeTrue();
    LearnerDailyStats::query()->whereIn('learner_id', LeagueMember::query()->where('league_group_id', $groups[0]->id)->pluck('learner_id'))->update(['reviews_count' => 999]);
    Queue::assertNothingPushed();

    expect(lgService()->closeWeek($week->fresh() ?? $week, $now))->toBeTrue()
        ->and($week->fresh()?->status)->toBe('closed')
        ->and($groups[0]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:00:30')
        ->and(lgResults($groups[0]))->toBe(['1:60:promoted', '2:50:promoted', '3:40:stayed', '4:30:stayed', '5:20:stayed', '6:10:stayed'])
        ->and($groups[2]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and($groups[4]->fresh()?->closed_at?->utc()->toDateTimeString())->toBe('2026-10-18 19:05:00')
        ->and(lgQueuedMemberIds())->toHaveCount(18)
        ->and(ProductEvent::query()->where('name', ProductEventName::LeagueWeekClosed->value)->count())->toBe(1);
});

it('queues no result DM for a banned, DM-closed, opted-out or deleted member', function (): void {
    Queue::fake();
    ['week' => $week, 'learners' => $learners] = lgWeekToClose();
    $learners[0][0]->forceFill(['blocked_at' => now()])->save();
    $learners[0][1]->forceFill(['dm_blocked_at' => now()])->save();
    $learners[0][2]->forceFill(['meta' => [LeagueService::OPT_OUT_META => true]])->save();
    $learners[0][3]->delete();

    lgService()->closeWeek($week, lgNow('2026-10-18 19:05:00'));

    $skipped = LeagueMember::query()->whereIn('learner_id', [$learners[0][0]->id, $learners[0][1]->id, $learners[0][2]->id, $learners[0][3]->id])->pluck('id')->map(fn ($id): int => (int) $id)->all();
    expect(lgQueuedMemberIds())->toHaveCount(14)
        ->and(array_intersect(lgQueuedMemberIds(), $skipped))->toBe([])
        // Their results are still written.
        ->and(LeagueMember::query()->where('league_week_id', $week->id)->whereNull('result')->count())->toBe(0);
});
