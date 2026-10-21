<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Engagement\Services\StreakFreezeService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerClock;
use App\Domain\Learners\Services\StreakService;
use App\Domain\Learners\Services\TodayService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * docs/13 §2.4, §7.2 / docs/06 §6a — streak freeze: spent only when exactly one local day was missed,
 * only with the Pro feature, once per date, never inside the review transaction; +1 per local week.
 */

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

const FREEZE_NOW = '2026-10-18 05:00:00'; // Tashkent: 10:00 on Sunday the 18th

/** A learner whose series of `$streak` days ended on `$last` (projection only) with `$freezes` in stock. */
function freezeLearner(int $streak, ?string $last, int $freezes, bool $pro = true, string $tz = 'Asia/Tashkent'): Learner
{
    $learner = Learner::factory()->create([
        'timezone' => $tz,
        'daily_goal' => 2,
        'streak_current' => $streak,
        'streak_best' => $streak,
        'streak_last_date' => $last,
        'streak_freezes' => $freezes,
    ]);

    if ($pro) {
        Subscription::factory()->create(['subscriber_id' => $learner->id]);
    }

    return $learner;
}

function freezeState(Learner $learner, string $utc = FREEZE_NOW): array
{
    $now = CarbonImmutable::parse($utc, 'UTC');
    $today = app(LearnerClock::class)->today($learner, $now);

    return app(StreakService::class)->current($learner, $today, null, $now)->toArray();
}

/** @return array{freezes: int, refilled_on: string|null, current: int, last: string|null} */
function freezeStored(Learner $learner): array
{
    $fresh = Learner::query()->findOrFail($learner->id);

    return [
        'freezes' => $fresh->streak_freezes,
        'refilled_on' => $fresh->streak_freezes_refilled_on?->toDateString(),
        'current' => $fresh->streak_current,
        'last' => $fresh->streak_last_date?->toDateString(),
    ];
}

function frozenRow(Learner $learner, string $date): ?LearnerDailyStats
{
    return LearnerDailyStats::query()
        ->where('learner_id', $learner->id)
        ->where('date', (string) (new LearnerDailyStats)->fromDateTime($date))
        ->whereNotNull('frozen_at')
        ->first();
}

function freezeFact(Learner $learner, string $date, bool $goalMet): void
{
    LearnerDailyStats::factory()->create([
        'learner_id' => $learner->id,
        'date' => $date,
        'reviews_count' => $goalMet ? 2 : 0,
        'goal_met_at' => $goalMet ? CarbonImmutable::parse($date.' 10:00:00', 'UTC') : null,
        'frozen_at' => $goalMet ? null : CarbonImmutable::parse($date.' 23:00:00', 'UTC'),
    ]);
}

function frozenEvents(Learner $learner): Collection
{
    return ProductEvent::query()->where('learner_id', $learner->id)->where('name', ProductEventName::StreakFrozen->value)->get();
}

it('spends a freeze on read when exactly one day was missed and keeps the series', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 2);

    expect(freezeState($learner))->toBe([
        'current' => 3, 'best' => 3, 'met_today' => false, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => true,
    ])->and(freezeStored($learner))->toMatchArray(['freezes' => 1, 'current' => 3, 'last' => '2026-10-17']);

    $row = frozenRow($learner, '2026-10-17');
    expect($row)->not->toBeNull()
        ->and($row?->goal_met_at)->toBeNull()
        ->and($row?->frozen_at?->utc()->toDateTimeString())->toBe(FREEZE_NOW)
        ->and($row?->reviews_count)->toBe(0);

    $events = frozenEvents($learner);
    expect($events)->toHaveCount(1)
        ->and($events[0]->props)->toMatchArray(['date' => '2026-10-17', 'freezes_left' => 1])
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->count())->toBe(0);
});

it('does not spend a freeze when no day was missed', function (): void {
    $yesterday = freezeLearner(3, '2026-10-17', 2);
    expect(freezeState($yesterday))->toMatchArray(['current' => 3, 'at_risk' => true, 'freezes' => 2, 'frozen_yesterday' => false])
        ->and(freezeStored($yesterday)['freezes'])->toBe(2);

    $today = freezeLearner(3, '2026-10-18', 2);
    expect(freezeState($today))->toMatchArray(['current' => 3, 'met_today' => true, 'freezes' => 2, 'frozen_yesterday' => false])
        ->and(freezeStored($today)['freezes'])->toBe(2);

    expect(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0);
});

it('spends one freeze per missed day when N days in a row were missed and N freezes are in stock', function (): void {
    $learner = freezeLearner(3, '2026-10-15', 2); // 16th and 17th missed

    expect(freezeState($learner))->toMatchArray(['current' => 3, 'freezes' => 0, 'frozen_yesterday' => true])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 3, 'last' => '2026-10-17'])
        ->and(frozenRow($learner, '2026-10-16'))->not->toBeNull()
        ->and(frozenRow($learner, '2026-10-17'))->not->toBeNull()
        ->and(frozenEvents($learner))->toHaveCount(2);

    // Idempotent: a second read or a retried freeze spends nothing more.
    freezeState($learner);
    expect(freezeStored($learner)['freezes'])->toBe(0)
        ->and(frozenEvents($learner))->toHaveCount(2)
        ->and(app(StreakFreezeService::class)->tryFreezeMany($learner, ['2026-10-16', '2026-10-17'], CarbonImmutable::parse(FREEZE_NOW, 'UTC')))->toBeFalse();
});

it('burns the series and spends nothing when fewer freezes than missed days are in stock', function (): void {
    $short = freezeLearner(3, '2026-10-15', 1);
    expect(freezeState($short))->toMatchArray(['current' => 0, 'best' => 3, 'freezes' => 1, 'frozen_yesterday' => false])
        ->and(freezeStored($short))->toMatchArray(['freezes' => 1, 'current' => 0, 'last' => '2026-10-15'])
        ->and(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0);

    $none = freezeLearner(3, '2026-10-15', 0);
    expect(freezeState($none)['current'])->toBe(0);

    // More missed days than the inventory can ever hold (max 2).
    $many = freezeLearner(9, '2026-09-01', 2);
    expect(freezeState($many)['current'])->toBe(0)
        ->and(freezeStored($many)['freezes'])->toBe(2)
        ->and(LearnerDailyStats::query()->where('learner_id', $many->id)->whereNotNull('frozen_at')->count())->toBe(0);

    $three = freezeLearner(3, '2026-10-14', 2); // 15th, 16th, 17th missed: three days, two freezes
    expect(freezeState($three)['current'])->toBe(0)
        ->and(freezeStored($three)['freezes'])->toBe(2);
});

it('freezes all missed days after commit when today\'s goal closes an N-day gap, or restarts at 1 when they do not suffice', function (): void {
    $now = CarbonImmutable::parse(FREEZE_NOW, 'UTC');

    $enough = freezeLearner(3, '2026-10-15', 2);
    expect(app(StreakService::class)->apply($enough, '2026-10-18', $now))->toBeTrue()
        ->and(freezeStored($enough))->toMatchArray(['freezes' => 0, 'current' => 4, 'last' => '2026-10-18'])
        ->and(frozenRow($enough, '2026-10-16'))->not->toBeNull()
        ->and(frozenRow($enough, '2026-10-17'))->not->toBeNull();

    $short = freezeLearner(3, '2026-10-15', 1);
    expect(app(StreakService::class)->apply($short, '2026-10-18', $now))->toBeTrue()
        ->and(freezeStored($short))->toMatchArray(['freezes' => 1, 'current' => 1, 'last' => '2026-10-18'])
        ->and(LearnerDailyStats::query()->where('learner_id', $short->id)->whereNotNull('frozen_at')->count())->toBe(0);
});

it('does nothing for a Free learner: no freeze, zero shown, the stored inventory untouched', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 2, pro: false);

    expect(freezeState($learner))->toBe([
        'current' => 0, 'best' => 3, 'met_today' => false, 'at_risk' => false, 'freezes' => 0, 'frozen_yesterday' => false,
    ])->and(freezeStored($learner))->toMatchArray(['freezes' => 2, 'last' => '2026-10-16'])
        ->and(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0)
        ->and(app(StreakFreezeService::class)->tryFreeze($learner, '2026-10-17', CarbonImmutable::parse(FREEZE_NOW, 'UTC')))->toBeFalse();
});

it('breaks the series when a Pro learner has no freeze left', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 0);

    expect(freezeState($learner))->toMatchArray(['current' => 0, 'freezes' => 0, 'frozen_yesterday' => false])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'last' => '2026-10-16']);
});

it('spends at most one freeze per date however many times the day is read or tried', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 2);
    $now = CarbonImmutable::parse(FREEZE_NOW, 'UTC');

    freezeState($learner);
    freezeState($learner);
    freezeState(Learner::query()->findOrFail($learner->id));
    expect(app(StreakFreezeService::class)->tryFreeze($learner, '2026-10-17', $now))->toBeFalse();

    expect(freezeStored($learner)['freezes'])->toBe(1)
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(1)
        ->and(frozenEvents($learner))->toHaveCount(1);
});

it('does not freeze a day whose goal was actually met, and gives the freeze back', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 2);
    freezeFact($learner, '2026-10-17', goalMet: true); // the callback of the 17th was lost

    expect(app(StreakFreezeService::class)->tryFreeze($learner, '2026-10-17', CarbonImmutable::parse(FREEZE_NOW, 'UTC')))->toBeFalse()
        ->and(freezeStored($learner)['freezes'])->toBe(2)
        ->and(frozenRow($learner, '2026-10-17'))->toBeNull();

    // On read the lost day is repaired from the fact — no freeze needed.
    expect(freezeState($learner))->toMatchArray(['current' => 4, 'freezes' => 2, 'frozen_yesterday' => false]);
});

it('freezes yesterday after commit when today\'s goal closes a one-day gap — never inside the review transaction', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 1);
    $stats = app(DailyStatsService::class);
    $now = CarbonImmutable::parse(FREEZE_NOW, 'UTC');

    DB::transaction(function () use ($learner, $stats, $now): void {
        $stats->recordReview($learner, 4, 500, false, $now);
        $stats->recordReview($learner, 4, 500, false, $now); // goal (2) met here

        // Still inside the review transaction: nothing of the freeze or the projection happened.
        expect(freezeStored($learner))->toMatchArray(['freezes' => 1, 'current' => 3, 'last' => '2026-10-16'])
            ->and(frozenRow($learner, '2026-10-17'))->toBeNull();
    });

    expect(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 4, 'last' => '2026-10-18'])
        ->and(frozenRow($learner, '2026-10-17'))->not->toBeNull()
        ->and(frozenEvents($learner))->toHaveCount(1);

    $names = ProductEvent::query()->where('learner_id', $learner->id)->orderBy('id')->pluck('name')->all();
    expect($names)->toContain(ProductEventName::StreakFrozen->value, ProductEventName::DailyGoalMet->value, ProductEventName::StreakExtended->value)
        ->and(array_search(ProductEventName::StreakFrozen->value, $names, true))
        ->toBeLessThan((int) array_search(ProductEventName::DailyGoalMet->value, $names, true));

    expect(freezeState($learner))->toMatchArray(['current' => 4, 'met_today' => true, 'freezes' => 0, 'frozen_yesterday' => true]);
});

it('restarts at 1 when the goal closes a one-day gap and no freeze is left', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 0);

    expect(app(StreakService::class)->apply($learner, '2026-10-18', CarbonImmutable::parse(FREEZE_NOW, 'UTC')))->toBeTrue()
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 1, 'last' => '2026-10-18'])
        ->and(frozenRow($learner, '2026-10-17'))->toBeNull();
});

it('rebuilds the series from the facts with frozen days as bridges that are not counted', function (): void {
    $learner = freezeLearner(0, null, 0);
    freezeFact($learner, '2026-10-12', true);
    freezeFact($learner, '2026-10-13', true);
    freezeFact($learner, '2026-10-14', false); // frozen
    freezeFact($learner, '2026-10-15', true);

    app(StreakService::class)->apply($learner, '2026-10-16');
    expect(freezeStored($learner))->toMatchArray(['current' => 4, 'last' => '2026-10-16']);

    // A frozen day at the end of the run bridges to the new day as well.
    $other = freezeLearner(0, null, 0);
    freezeFact($other, '2026-10-13', true);
    freezeFact($other, '2026-10-14', true);
    freezeFact($other, '2026-10-15', false);
    app(StreakService::class)->apply($other, '2026-10-16');
    expect(freezeStored($other))->toMatchArray(['current' => 3, 'last' => '2026-10-16']);

    // Two frozen days in a row still bridge; a hole does not.
    $hole = freezeLearner(0, null, 0);
    freezeFact($hole, '2026-10-11', true);
    freezeFact($hole, '2026-10-13', false);
    freezeFact($hole, '2026-10-14', false);
    freezeFact($hole, '2026-10-15', true);
    app(StreakService::class)->apply($hole, '2026-10-16');
    expect(freezeStored($hole))->toMatchArray(['current' => 2, 'last' => '2026-10-16']);
});

it('current() repairs a frozen yesterday whose projection was lost without crediting it', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 1);
    freezeFact($learner, '2026-10-17', false);

    expect(freezeState($learner))->toMatchArray(['current' => 3, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => true])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 1, 'current' => 3, 'last' => '2026-10-17'])
        ->and(ProductEvent::query()->where('learner_id', $learner->id)->count())->toBe(0);
});

it('freezes yesterday when the projection lags behind a met day before it (lost callback)', function (): void {
    $learner = freezeLearner(2, '2026-10-15', 1);
    freezeFact($learner, '2026-10-16', true); // met, never projected; the 17th missed

    expect(freezeState($learner))->toMatchArray(['current' => 3, 'freezes' => 0, 'frozen_yesterday' => true])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 3, 'last' => '2026-10-17']);
});

it('freezes the whole tail after a met day whose callback was lost, counting the met day but not the frozen ones', function (): void {
    $learner = freezeLearner(2, '2026-10-14', 2);
    freezeFact($learner, '2026-10-15', true); // met, never projected; the 16th and the 17th missed

    expect(freezeState($learner))->toMatchArray(['current' => 3, 'freezes' => 0, 'frozen_yesterday' => true])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 3, 'last' => '2026-10-17'])
        ->and(frozenRow($learner, '2026-10-16'))->not->toBeNull()
        ->and(frozenRow($learner, '2026-10-17'))->not->toBeNull()
        ->and(frozenEvents($learner))->toHaveCount(2);
});

it('covers two consecutive missed days with two freezes, one per day, then breaks', function (): void {
    $learner = freezeLearner(5, '2026-10-16', 2);

    expect(freezeState($learner, '2026-10-18 05:00:00'))->toMatchArray(['current' => 5, 'freezes' => 1, 'frozen_yesterday' => true]);
    expect(freezeState($learner, '2026-10-19 05:00:00'))->toMatchArray(['current' => 5, 'freezes' => 0, 'frozen_yesterday' => true]);
    expect(freezeStored($learner))->toMatchArray(['freezes' => 0, 'current' => 5, 'last' => '2026-10-18']);

    expect(freezeState($learner, '2026-10-20 05:00:00'))->toMatchArray(['current' => 0, 'freezes' => 0, 'frozen_yesterday' => false]);
});

it('refills one freeze per local week up to the maximum, once however often the screen is read', function (): void {
    $learner = freezeLearner(0, null, 0);
    $service = app(StreakFreezeService::class);
    $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'Asia/Tashkent');

    expect($service->refill($learner, $day('2026-10-19')))->toBeTrue() // Monday
        ->and($learner->streak_freezes)->toBe(1)
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 1, 'refilled_on' => '2026-10-19']);

    expect($service->refill($learner, $day('2026-10-19')))->toBeFalse()
        ->and($service->refill($learner, $day('2026-10-21')))->toBeFalse()
        ->and($service->refill($learner, $day('2026-10-25')))->toBeFalse() // Sunday of the same week
        ->and(freezeStored($learner)['freezes'])->toBe(1);

    expect($service->refill($learner, $day('2026-10-29')))->toBeTrue() // Thursday: first read of the next week
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 2, 'refilled_on' => '2026-10-26']);

    // Full: the week is still marked, so spending on Tuesday is not refilled on Wednesday.
    expect($service->refill($learner, $day('2026-11-02')))->toBeFalse()
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 2, 'refilled_on' => '2026-11-02']);
    Learner::query()->whereKey($learner->id)->update(['streak_freezes' => 1]);
    expect($service->refill($learner, $day('2026-11-04')))->toBeFalse()
        ->and(freezeStored($learner)['freezes'])->toBe(1)
        ->and($service->refill($learner, $day('2026-11-09')))->toBeTrue()
        ->and(freezeStored($learner)['freezes'])->toBe(2);
});

it('does not refill a Free learner', function (): void {
    $learner = freezeLearner(0, null, 0, pro: false);

    expect(app(StreakFreezeService::class)->refill($learner, CarbonImmutable::parse('2026-10-19', 'Asia/Tashkent')))->toBeFalse()
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 0, 'refilled_on' => null]);
});

it('does not refill twice when a timezone change moves the local day back or forth within the week', function (): void {
    $learner = freezeLearner(0, null, 0);
    $service = app(StreakFreezeService::class);
    $clock = app(LearnerClock::class);
    $now = CarbonImmutable::parse('2026-10-19 02:00:00', 'UTC'); // Tashkent: Monday 07:00; Los Angeles: Sunday 19:00

    expect($service->refill($learner, $clock->today($learner, $now)))->toBeTrue();

    $learner->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    expect($clock->localDate($learner, $now))->toBe('2026-10-18')
        ->and($service->refill($learner, $clock->today($learner, $now)))->toBeFalse();

    $learner->forceFill(['timezone' => 'Pacific/Auckland'])->save(); // Monday 15:00
    expect($service->refill($learner, $clock->today($learner, $now)))->toBeFalse()
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 1, 'refilled_on' => '2026-10-19']);
});

it('does not spend a second freeze when a timezone change re-opens the frozen or the held day', function (): void {
    $learner = freezeLearner(3, '2026-10-16', 2);
    $clock = app(LearnerClock::class);
    $streaks = app(StreakService::class);
    $now = CarbonImmutable::parse('2026-10-17 22:00:00', 'UTC'); // Tashkent: 03:00 on the 18th

    $state = $streaks->current($learner, $clock->today($learner, $now), null, $now);
    expect($state->toArray())->toMatchArray(['current' => 3, 'freezes' => 1, 'frozen_yesterday' => true]);

    // West: 22:00 UTC is 15:00 on the 17th in Los Angeles — the frozen day is "today" again.
    $learner->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    $state = $streaks->current($learner, $clock->today($learner, $now), null, $now);
    expect($state->toArray())->toMatchArray(['current' => 3, 'met_today' => true, 'freezes' => 1, 'frozen_yesterday' => false])
        ->and(freezeStored($learner))->toMatchArray(['freezes' => 1, 'last' => '2026-10-17']);

    // Back east on the same instant: still the 18th, yesterday is already held.
    $learner->forceFill(['timezone' => 'Asia/Tashkent'])->save();
    $state = $streaks->current($learner, $clock->today($learner, $now), null, $now);
    expect($state->toArray())->toMatchArray(['current' => 3, 'freezes' => 1, 'frozen_yesterday' => true])
        ->and(LearnerDailyStats::query()->where('learner_id', $learner->id)->whereNotNull('frozen_at')->count())->toBe(1);
});

it('GET /today refills on Monday, reports the inventory and the frozen yesterday, and shows Free zero freezes', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 05:00:00'); // Tashkent: Monday the 19th, 10:00
    $headers = fn (Learner $l): array => ['Authorization' => 'Bearer '.app(JwtService::class)->issue($l->id)['token']];

    $pro = freezeLearner(4, '2026-10-17', 1); // Saturday held, Sunday missed, one freeze in stock
    Learner::query()->whereKey($pro->id)->update(['streak_freezes_refilled_on' => '2026-10-12']);
    $this->withHeaders($headers($pro))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('streak', ['current' => 4, 'best' => 4, 'met_today' => false, 'at_risk' => true, 'freezes' => 1, 'frozen_yesterday' => true]);
    expect(freezeStored($pro))->toMatchArray(['freezes' => 1, 'refilled_on' => '2026-10-19', 'last' => '2026-10-18']);

    // No freeze in stock when the screen is read: the series is burnt, the Monday refill does not bring it back.
    $burnt = freezeLearner(4, '2026-10-17', 0);
    $this->withHeaders($headers($burnt))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('streak.current', 0)
        ->assertJsonPath('streak.freezes', 1);
    expect(freezeStored($burnt))->toMatchArray(['freezes' => 1, 'current' => 0, 'refilled_on' => '2026-10-19']);

    $free = freezeLearner(4, '2026-10-17', 1, pro: false);
    $this->withHeaders($headers($free))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('streak.current', 0)
        ->assertJsonPath('streak.freezes', 0)
        ->assertJsonPath('streak.frozen_yesterday', false);
    expect(freezeStored($free))->toMatchArray(['freezes' => 1, 'refilled_on' => null]);
});

it('switches freezes off for everyone when the configured maximum is 0', function (): void {
    config(['learners.streak_freeze.max' => 0]);
    $learner = freezeLearner(3, '2026-10-16', 2);

    expect(freezeState($learner))->toMatchArray(['current' => 0, 'freezes' => 0, 'frozen_yesterday' => false])
        ->and(app(StreakFreezeService::class)->refill($learner, CarbonImmutable::parse('2026-10-19', 'Asia/Tashkent')))->toBeFalse()
        ->and(freezeStored($learner)['freezes'])->toBe(2);
});

it('burns an uncoverable gap for good: stored as 0, a later refill does not restore it and spends nothing', function (): void {
    $learner = freezeLearner(3, '2026-10-15', 1); // 16th and 17th missed, one freeze
    $now = CarbonImmutable::parse(FREEZE_NOW, 'UTC');

    expect(freezeState($learner))->toMatchArray(['current' => 0, 'best' => 3, 'freezes' => 1])
        ->and(freezeStored($learner))->toMatchArray(['current' => 0, 'freezes' => 1, 'last' => '2026-10-15']);

    // The Monday-style refill arrives afterwards: the series is already gone.
    $fresh = Learner::query()->findOrFail($learner->id);
    expect(app(StreakFreezeService::class)->refill($fresh, app(LearnerClock::class)->today($fresh, $now), $now))->toBeTrue();

    expect(freezeState($fresh))->toMatchArray(['current' => 0, 'best' => 3, 'freezes' => 2, 'frozen_yesterday' => false])
        ->and(freezeStored($fresh))->toMatchArray(['current' => 0, 'freezes' => 2])
        ->and(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0)
        ->and(frozenEvents($fresh))->toHaveCount(0);
});

it('does not use freezes for a burnt series when the goal is met later: it restarts at 1', function (): void {
    $learner = freezeLearner(3, '2026-10-15', 1);
    $now = CarbonImmutable::parse(FREEZE_NOW, 'UTC');

    freezeState($learner); // burns
    Learner::query()->whereKey($learner->id)->update(['streak_freezes' => 2]);

    $fresh = Learner::query()->findOrFail($learner->id);
    expect(app(StreakService::class)->apply($fresh, '2026-10-18', $now))->toBeTrue()
        ->and(freezeStored($fresh))->toMatchArray(['current' => 1, 'freezes' => 2, 'last' => '2026-10-18'])
        ->and(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0);
});

it('burns the series of a Free learner and of a Pro learner without freezes in the database too', function (): void {
    $free = freezeLearner(3, '2026-10-16', 2, pro: false);
    $none = freezeLearner(3, '2026-10-16', 0);

    freezeState($free);
    freezeState($none);

    expect(freezeStored($free))->toMatchArray(['current' => 0, 'freezes' => 2])
        ->and(freezeStored($none))->toMatchArray(['current' => 0, 'freezes' => 0])
        ->and(Learner::query()->findOrFail($none->id)->streak_best)->toBe(3);
});

it('GET /today does not let the Monday refill save a series that the stock could not cover when it was read', function (): void {
    CarbonImmutable::setTestNow('2026-10-19 05:00:00'); // Monday the 19th
    $now = CarbonImmutable::now();

    $learner = freezeLearner(4, '2026-10-16', 1); // 17th and 18th missed; the refill would make it 2
    Learner::query()->whereKey($learner->id)->update(['streak_freezes_refilled_on' => '2026-10-12']);

    $progress = app(TodayService::class)->summary($learner->fresh(), $now)->progress;

    expect($progress->streak->current)->toBe(0)
        ->and(freezeStored($learner))->toMatchArray(['current' => 0, 'freezes' => 2, 'refilled_on' => '2026-10-19'])
        ->and(LearnerDailyStats::query()->whereNotNull('frozen_at')->count())->toBe(0);
});
