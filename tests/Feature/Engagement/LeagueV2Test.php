<?php

declare(strict_types=1);

/*
 * The weekly league on the word points v2 (docs/32 §3.5, §5.1, §7; task S8): the version of the week written at its creation, the
 * placement at the first credit of a rating day into a group of the week's category (the tier carried over, the v1 hooks seat nobody),
 * the standings of the five best days (a better sixth day replaces the weakest), the competition ranks and their tie-breaks, the zones
 * with shared places (everybody sharing the last promoted place goes up, nobody sharing a place with the last safe one goes down),
 * the members out of the ranks, the close (final facts, idempotent), the cache key. The races are in LeagueV2RaceTest (PostgreSQL),
 * the statements in LeagueV2BudgetTest.
 */

use App\Domain\Billing\Enums\Plan;
use App\Domain\Engagement\Enums\LeagueResult;
use App\Domain\Engagement\Enums\ScoreCategory;
use App\Domain\Engagement\Enums\ScoreSource;
use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learning\Contracts\AnswerScoreRecorder;
use App\Domain\Learning\DTO\ScoredAnswer;
use App\Domain\Learning\DTO\WordScoreOutcome;
use App\Domain\Learning\Enums\AnswerVerdict;
use App\Domain\Operations\Support\PureCache;
use App\Models\Achievement;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerScoreDay;
use App\Models\LearnerScorePeriod;
use App\Models\Lexeme;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    config()->set('leagues.timezone', 'Asia/Tashkent');
    config()->set('leagues.scoring.mode', 'live');
    config()->set('leagues.scoring.versions.2.starts_on', '2027-02-01');
    config()->set('leagues.promote', 2);
    config()->set('leagues.demote', 2);
});

/** Wednesday 2027-02-03 12:00 in Tashkent; the week 2027-02-01 … 2027-02-07 is the first of v2. */
function lv2Now(string $local = '2027-02-03 12:00'): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Tashkent')->utc();
}

function lv2(): LeagueService
{
    return app(LeagueService::class);
}

/** A v2 week with one group of the category and tier, and the given learners seated in it. @param list<Learner> $learners */
function lv2Group(array $learners, ScoreCategory $category = ScoreCategory::Free, int $tier = 1): LeagueGroup
{
    $week = lv2()->currentWeek(lv2Now());
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $tier, 'category' => $category->value, 'members_count' => count($learners)]);
    foreach ($learners as $learner) {
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => $tier, 'category' => $category->value]);
    }

    return $group;
}

/** Rating days of the week 2027-02-01… with the given weekly points (and long words) - day 1 first. @param list<int|array{0: int, 1: int}> $days */
function lv2Days(Learner $learner, array $days): void
{
    foreach ($days as $i => $day) {
        [$points, $long] = is_array($day) ? $day : [$day, 0];
        LearnerScoreDay::factory()->create([
            'learner_id' => $learner->id,
            'rating_day' => CarbonImmutable::parse('2027-02-01')->addDays($i)->toDateString(),
            'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01',
            'week_points' => $points, 'week_long' => $long, 'month_points' => $points, 'month_long' => $long,
        ]);
    }
}

/** @return array<int, array{rank: int, points: int, long: int|null, days: int|null}> learner id => standing */
function lv2Standings(LeagueGroup $group, string $local = '2027-02-03 12:00'): array
{
    return lv2()->standings($group->fresh() ?? $group, lv2Now($local))
        ->mapWithKeys(fn ($s): array => [$s->learnerId => ['rank' => $s->rank, 'points' => $s->points, 'long' => $s->longWords, 'days' => $s->activeDays]])
        ->all();
}

function lv2Credit(int $learnerId, Plan $plan, CarbonImmutable $now): WordScoreOutcome
{
    $lexeme = Lexeme::factory()->create()->id;

    return DB::transaction(fn (): WordScoreOutcome => app(AnswerScoreRecorder::class)->record(
        new ScoredAnswer($learnerId, $lexeme, 1, ScoreSource::Training, 7, AnswerVerdict::Passed, null, $plan, $now),
    ));
}

it('writes the rules version into the week when the week is created, and a learner\'s week takes the version of that row', function (): void {
    $v2 = lv2()->currentWeek(lv2Now());
    expect($v2->fresh()?->rules_version)->toBe(2);

    // The week before the start counts by v1, whatever comes later.
    $v1 = lv2()->currentWeek(lv2Now('2027-01-27 12:00'));
    expect($v1->fresh()?->rules_version)->toBe(1);

    // A week row that says v1 while the configuration says v2 (the start date was moved into the past): the learners' weeks follow the row.
    config()->set('leagues.scoring.versions.2.starts_on', '2027-01-01');
    $learner = Learner::factory()->create();
    lv2Credit($learner->id, Plan::Free, lv2Now('2027-01-27 12:00'));
    expect(LearnerScorePeriod::query()->where('learner_id', $learner->id)->where('period', 'week')->sole()->rules_version)->toBe(1)
        ->and(LearnerScoreDay::query()->where('learner_id', $learner->id)->sole()->week_points)->toBe(0);
});

it('seats a learner at the first credit of the week into a group of the week\'s category, and the v1 hooks seat nobody', function (): void {
    $learner = Learner::factory()->create();
    $now = lv2Now();

    // The first grade of a local day (DailyStatsService) and the first run (RunFinisher) call place() without a category: nothing.
    expect(lv2()->place($learner, $now))->toBeNull()
        ->and(LeagueMember::query()->count())->toBe(0);

    $outcome = lv2Credit($learner->id, Plan::Pro, $now);
    expect($outcome->firstCreditOfWeek)->toBeTrue();

    $member = LeagueMember::query()->where('learner_id', $learner->id)->sole();
    expect($member->category)->toBe('pro')
        ->and($member->group?->category)->toBe('pro')
        ->and($member->group?->members_count)->toBe(1);

    // Later credits (another day too) change nothing: one membership.
    lv2Credit($learner->id, Plan::Pro, $now->addMinute());
    lv2Credit($learner->id, Plan::Pro, lv2Now('2027-02-04 09:00'));
    expect(LeagueMember::query()->where('learner_id', $learner->id)->count())->toBe(1);
});

it('keeps the category of the week when the plan changes inside it: Pro bought on Wednesday plays the week in Free', function (): void {
    $learner = Learner::factory()->create();
    lv2Credit($learner->id, Plan::Free, lv2Now('2027-02-01 09:00'));
    lv2Credit($learner->id, Plan::Pro, lv2Now('2027-02-03 09:00'));

    expect(LeagueMember::query()->where('learner_id', $learner->id)->sole()->category)->toBe('free')
        ->and(LearnerScoreDay::query()->where('learner_id', $learner->id)->pluck('week_category')->map->value->unique()->values()->all())->toBe(['free']);

    // The next week starts in Pro.
    lv2Credit($learner->id, Plan::Pro, lv2Now('2027-02-08 09:00'));
    $next = LeagueMember::query()->where('learner_id', $learner->id)->orderByDesc('id')->first();
    expect($next?->category)->toBe('pro');
});

it('carries the tier over from a Free group to a Pro group', function (): void {
    $learner = Learner::factory()->create();
    $last = LeagueWeek::factory()->create(['starts_on' => '2027-01-25', 'ends_on' => '2027-01-31', 'status' => 'closed', 'rules_version' => 1]);
    $group = LeagueGroup::factory()->create(['league_week_id' => $last->id, 'tier' => 2, 'closed_at' => lv2Now('2027-02-01 00:01')]);
    LeagueMember::factory()->create(['league_group_id' => $group->id, 'league_week_id' => $last->id, 'learner_id' => $learner->id, 'tier_before' => 2, 'final_rank' => 1, 'final_points' => 90, 'result' => LeagueResult::Promoted->value]);

    lv2Credit($learner->id, Plan::Pro, lv2Now());

    $member = LeagueMember::query()->where('learner_id', $learner->id)->where('league_week_id', '<>', $last->id)->sole();
    expect($member->tier_before)->toBe(3)
        ->and($member->group?->tier)->toBe(3)
        ->and($member->group?->category)->toBe('pro');
});

it('never puts more than the capacity in a group: the next learner opens a second group of the category', function (): void {
    config()->set('leagues.capacity', 2);
    $learners = Learner::factory()->count(3)->create();
    foreach ($learners as $learner) {
        lv2Credit($learner->id, Plan::Free, lv2Now());
    }
    $pro = Learner::factory()->create();
    lv2Credit($pro->id, Plan::Pro, lv2Now());

    expect(LeagueGroup::query()->where('category', 'free')->orderBy('id')->pluck('members_count')->all())->toBe([2, 1])
        ->and(LeagueGroup::query()->where('category', 'pro')->pluck('members_count')->all())->toBe([1]);
});

it('counts the five best days of the week: a sixth day better than the weakest replaces it (260 → 300)', function (): void {
    [$a, $b] = Learner::factory()->count(2)->create()->all();
    $group = lv2Group([$a, $b]);
    lv2Days($a, [80, 60, 50, 40, 30]);
    lv2Days($b, [10]);

    expect(lv2Standings($group)[$a->id])->toBe(['rank' => 1, 'points' => 260, 'long' => 0, 'days' => 5]);

    // Day 6 with 70: the 30 drops out.
    LearnerScoreDay::factory()->create(['learner_id' => $a->id, 'rating_day' => '2027-02-06', 'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01', 'week_points' => 70]);
    PureCache::forgetMany([LeagueService::cacheKey($group->id, 2)]);
    expect(lv2Standings($group)[$a->id])->toBe(['rank' => 1, 'points' => 300, 'long' => 0, 'days' => 5]);

    // Day 7 with 20 counts for nothing; a day of the next week is not in the week.
    LearnerScoreDay::factory()->create(['learner_id' => $a->id, 'rating_day' => '2027-02-07', 'week_starts_on' => '2027-02-01', 'month_starts_on' => '2027-02-01', 'week_points' => 20]);
    LearnerScoreDay::factory()->create(['learner_id' => $a->id, 'rating_day' => '2027-02-08', 'week_starts_on' => '2027-02-08', 'month_starts_on' => '2027-02-01', 'week_points' => 100]);
    PureCache::forgetMany([LeagueService::cacheKey($group->id, 2)]);
    expect(lv2Standings($group)[$a->id]['points'])->toBe(300);
});

it('breaks a tie of the best days by their long words, then by their days with points; the weaker of two equal days drops by long words', function (): void {
    [$a, $b, $c, $d] = Learner::factory()->count(4)->create()->all();
    $group = lv2Group([$a, $b, $c, $d]);
    // a and b: 100 points; a has 3 long words in the five days, b 1.
    lv2Days($a, [[50, 2], [50, 1]]);
    lv2Days($b, [[50, 1], [50, 0]]);
    // c: 100 points, 1 long word, but on three days - more days with points than b.
    lv2Days($c, [[40, 1], [30, 0], [30, 0]]);
    // d: six days of 20 with the long word on the sixth: the order of the days (points desc, long desc) keeps that day in the five.
    lv2Days($d, [20, 20, 20, 20, 20, [20, 1]]);

    expect(lv2Standings($group))->toBe([
        $a->id => ['rank' => 1, 'points' => 100, 'long' => 3, 'days' => 2],
        $d->id => ['rank' => 2, 'points' => 100, 'long' => 1, 'days' => 5],
        $c->id => ['rank' => 3, 'points' => 100, 'long' => 1, 'days' => 3],
        $b->id => ['rank' => 4, 'points' => 100, 'long' => 1, 'days' => 2],
    ]);
});

it('gives a shared place to equal tuples (1, 2, 2, 4) in the order of the placement', function (): void {
    [$a, $b, $c, $d] = Learner::factory()->count(4)->create()->all();
    $group = lv2Group([$a, $b, $c, $d]);
    lv2Days($a, [90]);
    lv2Days($b, [50]);
    lv2Days($c, [50]);
    lv2Days($d, [10]);

    expect(array_map(fn (array $s): int => $s['rank'], lv2Standings($group)))->toBe([$a->id => 1, $b->id => 2, $c->id => 2, $d->id => 4]);
});

it('promotes everybody who shares the last promoted place and demotes nobody who shares a place with the last safe one', function (): void {
    config()->set('leagues.promote', 2);
    config()->set('leagues.demote', 2);
    // Seven members, tier 1 (both zones exist): 90 | 50 50 50 | 30 | 10 10.
    $learners = Learner::factory()->count(7)->create()->all();
    $group = lv2Group($learners);
    foreach ([90, 50, 50, 50, 30, 10, 10] as $i => $points) {
        lv2Days($learners[$i], [$points]);
    }

    lv2()->closeWeek($group->week, lv2Now('2027-02-08 00:01'));

    $results = LeagueMember::query()->where('league_group_id', $group->id)->orderBy('id')->get()
        ->map(fn (LeagueMember $m): string => "{$m->final_rank}:{$m->final_points}:{$m->result}")->all();
    expect($results)->toBe([
        '1:90:promoted',
        '2:50:promoted', '2:50:promoted', '2:50:promoted', // rank 2 ≤ promote 2: all three
        '5:30:stayed',                                       // count 7 − demote 2 = 5: rank 5 is safe
        '6:10:demoted', '6:10:demoted',
    ]);

    // Five members 90 | 50 | 30 30 30, demote 2: the last safe position is 3, and the three at 30 share place 3 - nobody goes down.
    config()->set('leagues.promote', 1);
    $five = Learner::factory()->count(5)->create()->all();
    $week = LeagueWeek::factory()->create(['starts_on' => '2027-02-08', 'ends_on' => '2027-02-14', 'rules_version' => 2]);
    $second = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => 1, 'category' => 'free', 'members_count' => 5]);
    foreach ($five as $i => $learner) {
        LeagueMember::factory()->create(['league_group_id' => $second->id, 'league_week_id' => $week->id, 'learner_id' => $learner->id, 'tier_before' => 1]);
        LearnerScoreDay::factory()->create(['learner_id' => $learner->id, 'rating_day' => '2027-02-09', 'week_starts_on' => '2027-02-08', 'month_starts_on' => '2027-02-01', 'week_points' => [90, 50, 30, 30, 30][$i]]);
    }
    lv2()->closeWeek($week, lv2Now('2027-02-15 00:01'));
    expect(LeagueMember::query()->where('league_group_id', $second->id)->orderBy('id')->pluck('result')->all())
        ->toBe(['promoted', 'stayed', 'stayed', 'stayed', 'stayed']);
});

it('drops a deleted, blocked or opted-out member out of the ranks: the neighbours move up, the close keeps their tier', function (): void {
    [$a, $b, $c, $d, $e] = Learner::factory()->count(5)->create()->all();
    $group = lv2Group([$a, $b, $c, $d, $e]);
    lv2Days($a, [100]);
    lv2Days($b, [90]);
    lv2Days($c, [80]);
    lv2Days($d, [70]);
    lv2Days($e, [60]);

    $a->delete();
    $b->forceFill(['blocked_at' => lv2Now()])->save();
    $c->forceFill(['meta' => [...($c->meta ?? []), LeagueService::OPT_OUT_META => true]])->save();

    expect(lv2Standings($group))->toBe([
        $d->id => ['rank' => 1, 'points' => 70, 'long' => 0, 'days' => 1],
        $e->id => ['rank' => 2, 'points' => 60, 'long' => 0, 'days' => 1],
    ]);

    lv2()->closeWeek($group->week, lv2Now('2027-02-08 00:01'));
    $members = LeagueMember::query()->where('league_group_id', $group->id)->get()->keyBy('learner_id');
    expect($members[$a->id]->final_rank)->toBeNull()
        ->and($members[$a->id]->result)->toBe('stayed')
        ->and($members[$a->id]->final_points)->toBe(100)
        ->and($members[$b->id]->final_rank)->toBeNull()
        ->and($members[$c->id]->final_rank)->toBeNull()
        ->and($members[$d->id]->final_rank)->toBe(1)
        ->and($members[$e->id]->final_rank)->toBe(2)
        // the final standings list the ranked members only
        ->and(lv2()->standings($group->fresh(), lv2Now('2027-02-08 00:02'))->pluck('learnerId')->all())->toBe([$d->id, $e->id]);
});

it('closes a v2 week once: the final facts written, a repeat writes nothing, every shared winner gets league_top1', function (): void {
    [$a, $b, $c] = Learner::factory()->count(3)->create()->all();
    $group = lv2Group([$a, $b, $c]);
    lv2Days($a, [[60, 2], 40]);
    lv2Days($b, [[60, 2], 40]);
    lv2Days($c, [0]);

    $close = lv2Now('2027-02-08 00:01');
    expect(lv2()->closeWeek($group->week, $close))->toBeTrue();
    $first = LeagueMember::query()->where('league_group_id', $group->id)->orderBy('id')->get(['final_points', 'final_rank', 'result', 'final_long_words', 'final_days', 'updated_at'])->toArray();
    expect(array_map(fn (array $m): array => [$m['final_rank'], $m['final_points'], $m['final_long_words'], $m['final_days']], $first))
        ->toBe([[1, 100, 2, 2], [1, 100, 2, 2], [3, 0, 0, 0]]);

    expect(lv2()->closeWeek($group->week, $close->addMinutes(5)))->toBeFalse()
        ->and(LeagueMember::query()->where('league_group_id', $group->id)->orderBy('id')->get(['final_points', 'final_rank', 'result', 'final_long_words', 'final_days', 'updated_at'])->toArray())->toBe($first)
        ->and(Achievement::query()->where('key', 'league_top1')->pluck('learner_id')->sort()->values()->all())->toBe([$a->id, $b->id]);
});

it('caches the standings of a v2 week under v3 and of a v1 week under v2: another meaning, another key', function (): void {
    $learner = Learner::factory()->create();
    $group = lv2Group([$learner]);
    lv2Days($learner, [40]);
    lv2()->standings($group, lv2Now());

    expect(PureCache::store()->get('league:standings:v3:'.$group->id))->toBeArray()
        ->and(PureCache::store()->get('league:standings:v2:'.$group->id))->toBeNull()
        ->and(LeagueService::cacheKey(7, 1))->toBe('league:standings:v2:7');

    // The Today line reads the right key through the cached placement facts.
    $line = lv2()->todayLine($learner, lv2Now());
    expect($line?->rank)->toBe(1)
        ->and($line?->points)->toBe(40);
});

it('shows the own place of a v2 week with the table, the version and the points that overtake the next place', function (): void {
    [$a, $b, $c] = Learner::factory()->count(3)->create()->all();
    lv2Group([$a, $b, $c], ScoreCategory::Pro);
    lv2Days($a, [[50, 1]]);
    lv2Days($b, [[50, 0]]);
    lv2Days($c, [30]);

    $mine = lv2()->overview($c, lv2Now())->membership;
    expect($mine?->category)->toBe('pro')
        ->and($mine?->rulesVersion)->toBe(2)
        ->and($mine?->rank)->toBe(3)
        ->and($mine?->nextGap)->toBe(21)                                       // 50 − 30 + 1
        ->and(lv2()->overview($b, lv2Now())->membership?->nextGap)->toBe(1)   // behind only on long words: one point
        ->and(lv2()->overview($a, lv2Now())->membership?->nextGap)->toBeNull();
});
