<?php

declare(strict_types=1);

/*
 * `/today.streak.week` and `/today.streak.lost` (docs/40 §2.5, F4): the local week of the learner with what held each day, and the
 * series burnt at its last loss - written by StreakService::burn() in the UPDATE that zeroes the series, cleared by project() with the
 * first counting day of the new one. The statements of `/today` do not change (TodayStreakQueriesTest, TwaTodayMeQueryBudgetTest).
 */

use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Domain\Learners\Support\StoredDate;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['game.access' => 'off']));

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param  array<string, mixed>  $attributes */
function swLearner(array $attributes = [], bool $pro = false): Learner
{
    $learner = Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 2, ...$attributes]);
    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
    }

    return $learner;
}

/** A day row: `goal`, `game` or `freeze` held it, or nothing (`null` - a few grades, the goal not met). */
function swDay(Learner $learner, string $date, ?string $by): void
{
    LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => $date,
        'reviews_count' => $by === 'goal' ? 2 : 1,
        'goal_met_at' => $by === 'goal' ? CarbonImmutable::parse("{$date} 06:00:00") : null,
        'game_held_at' => $by === 'game' ? CarbonImmutable::parse("{$date} 06:00:00") : null,
        'frozen_at' => $by === 'freeze' ? CarbonImmutable::parse("{$date} 06:00:00") : null,
    ]);
}

function swToday(object $test, Learner $learner): TestResponse
{
    return $test->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']])->getJson('/api/twa/today')->assertOk();
}

/**
 * @param  array<string, string|null>  $held  `Y-m-d` => held_by
 * @return list<array{date: string, held_by: string|null, is_today: bool}>
 */
function swWeek(string $monday, string $today, array $held = []): array
{
    $week = [];
    $day = CarbonImmutable::parse($monday);
    for ($i = 0; $i < 7; $i++, $day = $day->addDay()) {
        $date = $day->toDateString();
        $week[] = ['date' => $date, 'held_by' => $held[$date] ?? null, 'is_today' => $date === $today];
    }

    return $week;
}

it('gives the seven days of the local week with what held each, null for an unheld day and for the days to come', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 05:00:00'); // Thursday 10:00 in Tashkent
    $learner = swLearner(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-10-21']);
    swDay($learner, '2026-10-18', 'goal');    // the Sunday before: not in the week
    swDay($learner, '2026-10-19', 'goal');
    swDay($learner, '2026-10-20', 'game');
    swDay($learner, '2026-10-21', 'freeze');
    swDay($learner, '2026-10-22', null);      // today: two grades, the goal not met yet

    expect(swToday($this, $learner)->json('streak.week'))->toBe(swWeek('2026-10-19', '2026-10-22', [
        '2026-10-19' => 'goal', '2026-10-20' => 'game', '2026-10-21' => 'freeze',
    ]));

    // A day held by the goal and by a run as well is a goal day (StreakHeldBy::fromRow); today's goal met shows on today.
    LearnerDailyStats::query()->where('learner_id', $learner->id)->where('date', StoredDate::of('2026-10-22'))->update(['reviews_count' => 2, 'goal_met_at' => now(), 'game_held_at' => now()]);
    expect(swToday($this, $learner)->json('streak.week.3'))->toBe(['date' => '2026-10-22', 'held_by' => 'goal', 'is_today' => true]);
});

it('starts the week on the Monday of the learner\'s zone, also across the end of a month and on a Monday itself', function (): void {
    // 2026-11-29 20:30 UTC is Sunday in UTC and already Monday 2026-11-30 01:30 in Tashkent.
    CarbonImmutable::setTestNow('2026-11-29 20:30:00');
    $tashkent = swLearner();
    $utc = swLearner(['timezone' => 'UTC']);
    swDay($tashkent, '2026-11-29', 'goal'); // yesterday for Tashkent: read for the series, outside the week

    expect(swToday($this, $tashkent)->json('streak.week'))->toBe(swWeek('2026-11-30', '2026-11-30'))
        ->and(swToday($this, $utc)->json('streak.week'))->toBe(swWeek('2026-11-23', '2026-11-29'))
        ->and(swToday($this, $tashkent)->json('streak.current'))->toBe(1); // yesterday's row (Sunday, the week before) still holds the series

    // Wednesday 2026-12-02: the week begins in November.
    CarbonImmutable::setTestNow('2026-12-02 05:00:00');
    swDay($tashkent, '2026-11-30', 'goal');
    swDay($tashkent, '2026-12-01', 'goal');
    expect(swToday($this, $tashkent)->json('streak.week'))->toBe(swWeek('2026-11-30', '2026-12-02', ['2026-11-30' => 'goal', '2026-12-01' => 'goal']));
});

it('reads the week in the one statement of the day rows: Monday-to-today with yesterday, nothing more', function (): void {
    CarbonImmutable::setTestNow('2026-10-25 05:00:00'); // Sunday: the whole week is behind
    $learner = swLearner(['streak_current' => 2, 'streak_best' => 2, 'streak_last_date' => '2026-10-24']);
    swDay($learner, '2026-10-23', 'goal');
    swDay($learner, '2026-10-24', 'goal');
    swToday($this, $learner);

    $queries = QueryLog::during(fn () => swToday($this, $learner));

    expect(QueryLog::matching($queries, '/from ["`]learner_daily_stats["`]/'))->toHaveCount(1)
        ->and(QueryLog::matching($queries, '/^select \* from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] >= \? and ["`]date["`] <= \?$/'))->toHaveCount(1);
});

it('tells a Free learner the series that burnt on a missed day, with the day it was noticed, until the first new day', function (): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00'); // Wednesday
    $learner = swLearner(['streak_current' => 6, 'streak_best' => 9, 'streak_last_date' => '2026-10-19']); // Tuesday missed

    $streak = swToday($this, $learner)->json('streak');
    expect($streak['current'])->toBe(0)
        ->and($streak['best'])->toBe(9)
        ->and($streak['lost'])->toBe(['days' => 6, 'on' => '2026-10-21'])
        ->and($learner->fresh()?->streak_lost_days)->toBe(6)
        ->and($learner->fresh()?->streak_lost_on?->toDateString())->toBe('2026-10-21');

    // The next day still says so - the day of the loss does not move.
    CarbonImmutable::setTestNow('2026-10-22 05:00:00');
    expect(swToday($this, $learner)->json('streak.lost'))->toBe(['days' => 6, 'on' => '2026-10-21']);

    // The first counting day of the new series clears it, in the UPDATE of the projection.
    swDay($learner, '2026-10-22', 'goal');
    $streak = swToday($this, $learner)->json('streak');
    expect($streak['current'])->toBe(1)
        ->and($streak['lost'])->toBeNull()
        ->and($learner->fresh()?->streak_lost_days)->toBeNull()
        ->and($learner->fresh()?->streak_lost_on)->toBeNull();
});

it('clears the loss the same day when the goal is met after the burn', function (): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00');
    $learner = swLearner(['streak_current' => 4, 'streak_best' => 4, 'streak_last_date' => '2026-10-18']);
    expect(swToday($this, $learner)->json('streak.lost'))->toBe(['days' => 4, 'on' => '2026-10-21']);

    swDay($learner, '2026-10-21', 'goal');
    app(StreakService::class)->apply(Learner::query()->findOrFail($learner->id), '2026-10-21');

    expect(swToday($this, $learner)->json('streak'))->toMatchArray(['current' => 1, 'lost' => null]);
});

it('loses nothing for a Pro learner whose freezes cover the gap: the frozen days show in the week, read once more', function (): void {
    CarbonImmutable::setTestNow('2026-10-22 05:00:00'); // Thursday; Tuesday and Wednesday missed, two freezes in stock
    $learner = swLearner(['streak_current' => 5, 'streak_best' => 5, 'streak_last_date' => '2026-10-19', 'streak_freezes' => 2, 'streak_freezes_refilled_on' => '2026-10-19'], pro: true);
    swDay($learner, '2026-10-19', 'goal');

    $queries = QueryLog::during(fn () => $this->streakResponse = swToday($this, $learner));
    $streak = $this->streakResponse->json('streak');

    expect($streak['current'])->toBe(5)
        ->and($streak['frozen_yesterday'])->toBeTrue()
        ->and($streak['lost'])->toBeNull()
        ->and($streak['week'])->toBe(swWeek('2026-10-19', '2026-10-22', ['2026-10-19' => 'goal', '2026-10-20' => 'freeze', '2026-10-21' => 'freeze']))
        // The freezes were spent by this very request, after the rows were read: the week reads them again - once.
        ->and(QueryLog::matching($queries, '/^select \* from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] >= \?/'))->toHaveCount(2);

    // The next read: the rows already say "frozen", no second read.
    $again = QueryLog::during(fn () => swToday($this, $learner));
    expect(QueryLog::matching($again, '/^select \* from ["`]learner_daily_stats["`] where ["`]learner_id["`] = \? and ["`]date["`] >= \?/'))->toHaveCount(1);
});

it('burns a Pro series whose gap is longer than the freezes and tells how long it was', function (): void {
    CarbonImmutable::setTestNow('2026-10-23 05:00:00'); // Friday; Tuesday, Wednesday and Thursday missed, two freezes
    $learner = swLearner(['streak_current' => 8, 'streak_best' => 8, 'streak_last_date' => '2026-10-19', 'streak_freezes' => 2, 'streak_freezes_refilled_on' => '2026-10-19'], pro: true);
    swDay($learner, '2026-10-19', 'goal');

    $streak = swToday($this, $learner)->json('streak');

    expect($streak['current'])->toBe(0)
        ->and($streak['frozen_yesterday'])->toBeFalse()
        ->and($streak['lost'])->toBe(['days' => 8, 'on' => '2026-10-23'])
        ->and($streak['week'])->toBe(swWeek('2026-10-19', '2026-10-23', ['2026-10-19' => 'goal']))
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(0);
});

it('never tells a loss to a learner who had no series', function (): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00');
    $learner = swLearner();

    expect(swToday($this, $learner)->json('streak.lost'))->toBeNull()
        ->and($learner->fresh()?->streak_lost_days)->toBeNull();
});

it('gives the loser of a concurrent burn the loss the winner stored', function (): void {
    CarbonImmutable::setTestNow('2026-10-21 05:00:00');
    $learner = swLearner(['streak_current' => 7, 'streak_best' => 7, 'streak_last_date' => '2026-10-18']);
    $stale = Learner::query()->findOrFail($learner->id); // loaded before the other request burnt the series
    $today = app(LearnerClock::class)->today($learner);

    $winner = app(StreakService::class)->current(Learner::query()->findOrFail($learner->id), $today);
    $loser = app(StreakService::class)->current($stale, $today);

    expect($winner->lost)->toBe(['days' => 7, 'on' => '2026-10-21'])
        ->and($loser->current)->toBe(0)
        ->and($loser->lost)->toBe(['days' => 7, 'on' => '2026-10-21'])
        ->and($stale->streak_lost_days)->toBe(7)
        ->and($learner->fresh()?->streak_lost_days)->toBe(7);
});
