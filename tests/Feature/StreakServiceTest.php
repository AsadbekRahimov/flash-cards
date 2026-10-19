<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
 * docs/12 §7.4 — streak rules, lazy zeroing, timezone changes, repair of the learners projection.
 */

uses(RefreshDatabase::class);

function streakLearner(string $tz = 'Asia/Tashkent', int $goal = 2): Learner
{
    return Learner::factory()->create(['timezone' => $tz, 'daily_goal' => $goal]);
}

/** Meets the goal (`daily_goal` grades) at the given UTC instant, each grade in its own transaction. */
function meetGoal(Learner $learner, string $utc): void
{
    for ($i = 0; $i < $learner->daily_goal; $i++) {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, CarbonImmutable::parse($utc, 'UTC')));
    }
}

function localDay(Learner $learner, string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, app(LearnerClock::class)->timezone($learner));
}

/** @return array{int, int, string|null} [current, best, last] as stored */
function storedStreak(Learner $learner): array
{
    $fresh = Learner::query()->findOrFail($learner->id);

    return [$fresh->streak_current, $fresh->streak_best, $fresh->streak_last_date?->toDateString()];
}

it('extends the streak on consecutive local days and restarts at 1 after a skipped day', function (): void {
    $learner = streakLearner();
    $streaks = app(StreakService::class);

    meetGoal($learner, '2026-10-18 10:00:00');
    expect(storedStreak($learner))->toBe([1, 1, '2026-10-18']);

    meetGoal($learner, '2026-10-19 10:00:00');
    expect(storedStreak($learner))->toBe([2, 2, '2026-10-19']);

    // Day 20 skipped. Reading on the 21st already shows 0 (lazy zeroing) ...
    $onThe21st = $streaks->current(Learner::query()->findOrFail($learner->id), localDay($learner, '2026-10-21'));
    expect($onThe21st->current)->toBe(0)
        ->and($onThe21st->best)->toBe(2)
        ->and($onThe21st->atRisk)->toBeFalse()
        ->and($onThe21st->metToday)->toBeFalse();

    // ... and meeting the goal on the 21st restarts the series while keeping best.
    meetGoal($learner, '2026-10-21 10:00:00');
    expect(storedStreak($learner))->toBe([1, 2, '2026-10-21']);
});

it('reports at_risk on the day after the last credited one and met_today once credited', function (): void {
    $learner = streakLearner();
    $streaks = app(StreakService::class);

    meetGoal($learner, '2026-10-18 10:00:00');
    $fresh = Learner::query()->findOrFail($learner->id);

    $sameDay = $streaks->current($fresh, localDay($learner, '2026-10-18'));
    expect($sameDay->toArray())->toBe(['current' => 1, 'best' => 1, 'met_today' => true, 'at_risk' => false]);

    $nextDay = $streaks->current($fresh, localDay($learner, '2026-10-19'));
    expect($nextDay->toArray())->toBe(['current' => 1, 'best' => 1, 'met_today' => false, 'at_risk' => true]);

    $dayAfter = $streaks->current($fresh, localDay($learner, '2026-10-20'));
    expect($dayAfter->toArray())->toBe(['current' => 0, 'best' => 1, 'met_today' => false, 'at_risk' => false]);
});

it('credits a day only once however many times the goal is "met" on it', function (): void {
    $learner = streakLearner();
    $streaks = app(StreakService::class);

    expect($streaks->apply($learner, '2026-10-18'))->toBeTrue()
        ->and($streaks->apply($learner, '2026-10-18'))->toBeFalse()
        ->and(storedStreak($learner))->toBe([1, 1, '2026-10-18'])
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(1);
});

it('uses the local day boundary: goal met at 23:59 then at 00:01 local counts two consecutive days', function (): void {
    $learner = streakLearner('Asia/Tashkent');

    meetGoal($learner, '2026-10-18 18:59:00'); // local 2026-10-18 23:59
    meetGoal($learner, '2026-10-18 19:01:00'); // local 2026-10-19 00:01

    expect(storedStreak($learner))->toBe([2, 2, '2026-10-19']);
});

it('moving the timezone west re-opens an already credited date without a second credit and without breaking the series', function (): void {
    $learner = streakLearner('Asia/Tashkent');
    meetGoal($learner, '2026-10-17 10:00:00');
    meetGoal($learner, '2026-10-18 17:00:00'); // local 22:00 on the 18th → streak 2

    // Settings change to New York: 17:00 UTC is 13:00 on the 18th — "today" is the 18th again.
    $learner->forceFill(['timezone' => 'America/New_York'])->save();
    $clock = app(LearnerClock::class);
    $now = CarbonImmutable::parse('2026-10-18 17:30:00', 'UTC');
    expect($clock->localDate($learner, $now))->toBe('2026-10-18');

    // More grades on the re-opened day: no new goal event, streak untouched.
    meetGoal($learner, '2026-10-18 17:30:00');
    expect(storedStreak($learner))->toBe([2, 2, '2026-10-18'])
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(2);

    $state = app(StreakService::class)->current(Learner::query()->findOrFail($learner->id), $clock->today($learner, $now));
    expect($state->current)->toBe(2)->and($state->metToday)->toBeTrue()->and($state->atRisk)->toBeFalse();
});

it('moving the timezone east may open the next date early but never yields more than one credit per date', function (): void {
    $learner = streakLearner('Asia/Tashkent');
    meetGoal($learner, '2026-10-18 06:00:00'); // local 11:00 on the 18th → streak 1

    // Settings change to Auckland (UTC+13 in October): 15:00 UTC is 04:00 on the 19th.
    $learner->forceFill(['timezone' => 'Pacific/Auckland'])->save();
    $clock = app(LearnerClock::class);
    $now = CarbonImmutable::parse('2026-10-18 15:00:00', 'UTC');
    expect($clock->localDate($learner, $now))->toBe('2026-10-19');

    // The learner is at risk, not zeroed; a full goal on the "early" 19th is one honest credit.
    $before = app(StreakService::class)->current(Learner::query()->findOrFail($learner->id), $clock->today($learner, $now));
    expect($before->current)->toBe(1)->and($before->atRisk)->toBeTrue();

    meetGoal($learner, '2026-10-18 15:00:00');
    meetGoal($learner, '2026-10-18 16:00:00'); // still the 19th locally — no second credit
    expect(storedStreak($learner))->toBe([2, 2, '2026-10-19'])
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('goal_met_at')->count())->toBe(2);
});

it('never credits a date earlier than the last credited one (goal met on "yesterday" after moving west)', function (): void {
    $learner = streakLearner('Pacific/Auckland');
    meetGoal($learner, '2026-10-18 12:00:00'); // local 2026-10-19 01:00 → last = 19th

    $learner->forceFill(['timezone' => 'America/Los_Angeles'])->save(); // 12:30 UTC = 05:30 on the 18th
    meetGoal($learner, '2026-10-18 12:30:00');                           // goal met on the 18th → ignored

    expect(storedStreak($learner))->toBe([1, 1, '2026-10-19'])
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('goal_met_at')->count())->toBe(2);
});

it('tracks the best streak independently of the current one', function (): void {
    $learner = streakLearner();
    $streaks = app(StreakService::class);

    foreach (['2026-10-01', '2026-10-02', '2026-10-03'] as $d) {
        $streaks->apply($learner, $d);
    }
    $streaks->apply($learner, '2026-10-05'); // gap → restart

    expect(storedStreak($learner))->toBe([1, 3, '2026-10-05']);

    $state = $streaks->current(Learner::query()->findOrFail($learner->id), localDay($learner, '2026-10-05'));
    expect($state->current)->toBe(1)->and($state->best)->toBe(3);
});

it('records daily_goal.met on every credit and streak.extended only when the series continues', function (): void {
    $learner = streakLearner();
    $streaks = app(StreakService::class);

    $streaks->apply($learner, '2026-10-01');
    $streaks->apply($learner, '2026-10-02');
    $streaks->apply($learner, '2026-10-04');

    $met = ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->orderBy('id')->get();
    $extended = ProductEvent::query()->where('name', ProductEventName::StreakExtended->value)->get();

    expect($met->pluck('props.streak')->all())->toBe([1, 2, 1])
        ->and($met->pluck('learner_id')->unique()->all())->toBe([$learner->id])
        ->and($extended)->toHaveCount(1)
        ->and($extended[0]->props)->toMatchArray(['current' => 2, 'best' => 2]);
});

it('refreshes the passed model so the current request sees the new streak without reloading', function (): void {
    $learner = streakLearner();

    app(StreakService::class)->apply($learner, '2026-10-18');

    expect($learner->streak_current)->toBe(1)
        ->and($learner->streak_last_date?->toDateString())->toBe('2026-10-18')
        ->and($learner->isDirty())->toBeFalse();
});

it('repairs a goal met today that was never projected onto learners when current() gets today\'s stats row', function (): void {
    $learner = streakLearner();
    $learner->forceFill(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-10-17'])->save();

    // The review transaction committed goal_met_at, but the afterCommit callback was lost (process died).
    $todayStats = LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => '2026-10-18',
        'reviews_count' => 2,
        'goal_met_at' => CarbonImmutable::parse('2026-10-18 10:00:00', 'UTC'),
    ]);

    $state = app(StreakService::class)->current($learner, localDay($learner, '2026-10-18'), $todayStats);

    expect($state->toArray())->toBe(['current' => 4, 'best' => 4, 'met_today' => true, 'at_risk' => false])
        ->and(storedStreak($learner))->toBe([4, 4, '2026-10-18']);

    // Without the row nothing is repaired or written.
    $other = streakLearner();
    $other->forceFill(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-10-17'])->save();
    $state = app(StreakService::class)->current($other, localDay($other, '2026-10-18'));
    expect($state->toArray())->toBe(['current' => 3, 'best' => 3, 'met_today' => false, 'at_risk' => true])
        ->and(storedStreak($other))->toBe([3, 3, '2026-10-17']);
});

it('returns zeros for a learner who never met a goal', function (): void {
    $learner = streakLearner();

    expect(app(StreakService::class)->current($learner, localDay($learner, '2026-10-18'))->toArray())
        ->toBe(['current' => 0, 'best' => 0, 'met_today' => false, 'at_risk' => false]);
});
