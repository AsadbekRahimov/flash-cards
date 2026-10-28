<?php

declare(strict_types=1);

/*
 * GET /today and its streak block: what a Free and a Pro learner are shown, what the read stores (the series is
 * settled BEFORE the weekly freeze is handed out - docs/13 §7.2, §12 q.16), and what it costs.
 *
 * Only a plan with the `streak.freeze` feature can receive a freeze, so only for it does the screen settle the
 * series ahead of the weekly refill; every other learner is settled once, by the progress block.
 */

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

/** A Wednesday, 10:00 in Tashkent: the learner's local day is 2026-10-21, the week started on Monday 2026-10-19. */
const TODAY_STREAK_NOW = '2026-10-21 05:00:00';

function todayStreakLearner(int $streak, ?string $last, int $freezes = 0, bool $pro = false, ?string $refilledOn = null, ?int $best = null): Learner
{
    $learner = Learner::factory()->onboarded()->create([
        'timezone' => 'Asia/Tashkent',
        'daily_goal' => 2,
        'streak_current' => $streak,
        'streak_best' => $best ?? $streak,
        'streak_last_date' => $last,
        'streak_freezes' => $freezes,
        'streak_freezes_refilled_on' => $refilledOn,
    ]);

    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
    }

    return $learner;
}

function todayStreakGet(object $test, Learner $learner): TestResponse
{
    $headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];

    return $test->withHeaders($headers)->getJson('/api/twa/today');
}

/** @return array{current: int, best: int, last: string|null, freezes: int, refilled_on: string|null} */
function todayStreakStored(Learner $learner): array
{
    $fresh = Learner::query()->findOrFail($learner->id);

    return [
        'current' => $fresh->streak_current,
        'best' => $fresh->streak_best,
        'last' => $fresh->streak_last_date?->toDateString(),
        'freezes' => $fresh->streak_freezes,
        'refilled_on' => $fresh->streak_freezes_refilled_on?->toDateString(),
    ];
}

beforeEach(fn () => CarbonImmutable::setTestNow(TODAY_STREAK_NOW));

it('shows a Free learner who never studied an empty streak and stores nothing', function (): void {
    $learner = todayStreakLearner(0, null);

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('streak', ['current' => 0, 'best' => 0, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);

    expect(todayStreakStored($learner))->toBe(['current' => 0, 'best' => 0, 'last' => null, 'freezes' => 0, 'refilled_on' => null]);
});

it('shows a Free learner a living series as at risk, and changes nothing in the database', function (): void {
    $learner = todayStreakLearner(3, '2026-10-20', best: 5);

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('streak', ['current' => 3, 'best' => 5, 'met_today' => false, 'at_risk' => true, 'freezes' => 0, 'frozen_yesterday' => false]);

    expect(todayStreakStored($learner))->toBe(['current' => 3, 'best' => 5, 'last' => '2026-10-20', 'freezes' => 0, 'refilled_on' => null]);
});

it('burns the series of a Free learner who skipped days and keeps the idle stock and the week marker as they were', function (): void {
    $learner = todayStreakLearner(4, '2026-10-17', freezes: 2);

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('streak', ['current' => 0, 'best' => 4, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);

    expect(todayStreakStored($learner))->toBe(['current' => 0, 'best' => 4, 'last' => '2026-10-17', 'freezes' => 2, 'refilled_on' => null])
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(0);
});

it('repairs on read a day whose goal was met but never credited to the streak', function (): void {
    $learner = todayStreakLearner(0, null);
    LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => '2026-10-21',
        'reviews_count' => 2,
        'goal_met_at' => CarbonImmutable::parse('2026-10-21 04:30:00', 'UTC'),
    ]);

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('goal', ['target' => 2, 'done' => 2, 'met' => true])
        ->assertJsonPath('streak', ['current' => 1, 'best' => 1, 'met_today' => true, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false]);

    expect(todayStreakStored($learner))->toMatchArray(['current' => 1, 'last' => '2026-10-21']);
});

it('leaves a Pro learner whose week was already handled exactly as it is', function (): void {
    $learner = todayStreakLearner(3, '2026-10-20', freezes: 1, pro: true, refilledOn: '2026-10-19');

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('streak', ['current' => 3, 'best' => 3, 'met_today' => false, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => false]);

    expect(todayStreakStored($learner))->toBe(['current' => 3, 'best' => 3, 'last' => '2026-10-20', 'freezes' => 1, 'refilled_on' => '2026-10-19']);
});

it('hands a Pro learner the weekly freeze on the first read of the week, once', function (): void {
    $learner = todayStreakLearner(3, '2026-10-20', freezes: 1, pro: true, refilledOn: '2026-10-12');

    todayStreakGet($this, $learner)->assertOk()->assertJsonPath('streak.freezes', 2);
    expect(todayStreakStored($learner))->toBe(['current' => 3, 'best' => 3, 'last' => '2026-10-20', 'freezes' => 2, 'refilled_on' => '2026-10-19']);

    todayStreakGet($this, $learner)->assertOk()->assertJsonPath('streak.freezes', 2);
    expect(todayStreakStored($learner)['freezes'])->toBe(2);
});

it('spends a Pro learner\'s old stock on the missed day first and only then hands out the weekly freeze', function (): void {
    $learner = todayStreakLearner(3, '2026-10-19', freezes: 1, pro: true, refilledOn: '2026-10-12');

    todayStreakGet($this, $learner)->assertOk()
        ->assertJsonPath('streak', ['current' => 3, 'best' => 3, 'met_today' => false, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => true]);

    expect(todayStreakStored($learner))->toBe(['current' => 3, 'best' => 3, 'last' => '2026-10-20', 'freezes' => 1, 'refilled_on' => '2026-10-19'])
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(1);
});
