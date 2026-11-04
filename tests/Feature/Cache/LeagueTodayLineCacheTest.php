<?php

declare(strict_types=1);

/*
 * The league line of the Today screen from the pure cache (docs/19 K9, R12i): the facts of the learner's placement in the
 * week (`league:member:v1:{learner}:{monday}`) are written once the learner is placed and never for "not placed", so the
 * first grade of the week shows the line at once; the Monday in the key keeps a new week from reading the old one; the
 * close of a group drops its cached standings; the purge of an account drops the learner's facts.
 */

use App\Domain\Engagement\Services\LeagueService;
use App\Domain\Learners\Services\LearnerPurgeService;
use App\Domain\Operations\Support\PureCache;
use App\Models\LeagueGroup;
use App\Models\LeagueMember;
use App\Models\LeagueWeek;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

/** Wednesday 2026-10-21 10:00 in Tashkent (05:00 UTC); the week is 2026-10-19 … 2026-10-25. */
function llNow(string $time = '2026-10-21 05:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/** @return array{week: LeagueWeek, group: LeagueGroup, learners: list<Learner>} a group of three with 30 / 20 / 10 points */
function llGroup(string $monday = '2026-10-19', int $tier = 1): array
{
    $week = LeagueWeek::factory()->startingOn($monday)->create();
    $group = LeagueGroup::factory()->create(['league_week_id' => $week->id, 'tier' => $tier]);
    $learners = [];
    foreach ([30, 20, 10] as $i => $reviews) {
        $learner = Learner::factory()->create(['first_name' => 'L'.$i, 'timezone' => 'Asia/Tashkent']);
        LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $learner->id]);
        LearnerDailyStats::factory()->create(['learner_id' => $learner->id, 'date' => CarbonImmutable::parse($monday)->addDay()->toDateString(), 'reviews_count' => $reviews]);
        $learners[] = $learner;
    }

    return ['week' => $week, 'group' => $group, 'learners' => $learners];
}

it('caches the facts of a placed learner for the week and serves the line without a statement', function (): void {
    ['group' => $group, 'learners' => $learners] = llGroup();
    $service = app(LeagueService::class);
    $key = LeagueService::memberKey($learners[1]->id, llNow());

    expect(PureCache::store()->has($key))->toBeFalse();
    $line = $service->todayLine($learners[1], llNow());
    expect($line?->toArray())->toBe(['tier' => 1, 'tier_key' => 'silver', 'rank' => 2, 'points' => 20, 'members_count' => 3])
        ->and(PureCache::store()->get($key))->toBe(['week_id' => $group->league_week_id, 'member_id' => LeagueMember::query()->where('learner_id', $learners[1]->id)->value('id'), 'group_id' => $group->id, 'tier' => 1]);

    $queries = QueryLog::during(fn () => expect($service->todayLine($learners[1], llNow())?->toArray())->toBe($line->toArray()));
    expect($queries)->toBe([]);
});

it('never caches "not placed": the line appears the moment the learner is placed', function (): void {
    ['group' => $group] = llGroup();
    $newcomer = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);
    $service = app(LeagueService::class);

    expect($service->todayLine($newcomer, llNow()))->toBeNull()
        ->and(PureCache::store()->has(LeagueService::memberKey($newcomer->id, llNow())))->toBeFalse();

    LeagueMember::factory()->create(['league_group_id' => $group->id, 'learner_id' => $newcomer->id]);
    PureCache::store()->forget(LeagueService::CACHE_PREFIX.$group->id); // the standings of the group, as the placement path leaves them to their TTL

    expect($service->todayLine($newcomer, llNow())?->toArray())->toBe(['tier' => 1, 'tier_key' => 'silver', 'rank' => 4, 'points' => 0, 'members_count' => 4])
        ->and(PureCache::store()->has(LeagueService::memberKey($newcomer->id, llNow())))->toBeTrue();
});

it('keys the facts by the Monday of the league zone: the next week reads nothing of this one', function (): void {
    ['learners' => $learners] = llGroup();
    $service = app(LeagueService::class);
    expect($service->todayLine($learners[0], llNow()))->not->toBeNull();

    $nextWeek = llNow('2026-10-26 05:00:00'); // Monday 10:00 Tashkent of the week after
    expect(LeagueService::memberKey($learners[0]->id, $nextWeek))->not->toBe(LeagueService::memberKey($learners[0]->id, llNow()))
        ->and($service->todayLine($learners[0], $nextWeek))->toBeNull();

    // Sunday 23:30 Tashkent still belongs to this week; Monday 00:30 Tashkent does not.
    expect(LeagueService::memberKey($learners[0]->id, llNow('2026-10-25 18:30:00')))->toBe(LeagueService::memberKey($learners[0]->id, llNow()))
        ->and(LeagueService::memberKey($learners[0]->id, llNow('2026-10-25 19:30:00')))->toBe(LeagueService::memberKey($learners[0]->id, $nextWeek));
});

it('drops the cached standings of a group when it is closed: the final ones are read from the rows', function (): void {
    config(['leagues.promote' => 1, 'leagues.demote' => 1]);
    ['group' => $group, 'learners' => $learners] = llGroup('2026-10-12');
    $service = app(LeagueService::class);
    $standingsKey = LeagueService::CACHE_PREFIX.$group->id;

    $service->todayLine($learners[0], llNow('2026-10-14 05:00:00'));
    expect(PureCache::store()->has($standingsKey))->toBeTrue();

    expect($service->closeGroup($group, llNow('2026-10-19 05:00:00')))->toBeTrue()
        ->and(PureCache::store()->has($standingsKey))->toBeFalse();
});

it('drops the facts of a purged account', function (): void {
    ['learners' => $learners] = llGroup();
    CarbonImmutable::setTestNow(llNow());
    $service = app(LeagueService::class);
    $service->todayLine($learners[2], llNow());
    $key = LeagueService::memberKey($learners[2]->id, llNow());
    expect(PureCache::store()->has($key))->toBeTrue();

    $learners[2]->delete();
    Learner::withTrashed()->whereKey($learners[2]->id)->update(['deleted_at' => llNow()->subDays(31)]);
    expect(app(LearnerPurgeService::class)->purge($learners[2]->id, llNow()))->toBeTrue()
        ->and(PureCache::store()->has($key))->toBeFalse();
});
