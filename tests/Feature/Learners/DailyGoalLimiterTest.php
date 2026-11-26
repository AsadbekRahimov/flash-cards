<?php

declare(strict_types=1);

/*
 * The daily goal against the plan (docs/34 §2б.1–2б.3, P2 + P3; owner's decision docs/31 §5г): on Free at most 10, lowered by itself
 * when Pro ends, given back when Pro returns, refused (403 daily_goal_pro_required) above the limit. The races on PostgreSQL are in
 * DailyGoalLimiterConcurrencyTest.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learners\DTO\DailyGoalChange;
use App\Domain\Learners\Exceptions\DailyGoalLimitException;
use App\Domain\Learners\Services\DailyGoalLimiter;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\LearnerSettingsService;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-25 10:00:00');
    config()->set('billing.plans.free.limits.daily_goal_max', 10);
    config()->set('learners.daily_goal_options', [10, 20, 40]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array<string, string> */
function goalHeaders(Learner $learner): array
{
    return ['Authorization' => 'Bearer '.app(JwtService::class)->issue($learner->id)['token']];
}

/** @param  array<string, mixed>  $attributes */
function goalLearner(array $attributes = []): Learner
{
    return Learner::factory()->onboarded()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 40, ...$attributes]);
}

/** Pro of the learner's own (the welcome trial by default) until `$until`. */
function goalPro(Learner $learner, CarbonImmutable $until, string $provider = 'trial'): Subscription
{
    $subscription = Subscription::factory()->create([
        'subscriber_id' => $learner->id,
        'provider' => $provider,
        'status' => $provider === 'trial' ? 'trialing' : 'active',
        'starts_at' => $until->subDays(14),
        'current_period_ends_at' => $until,
    ]);
    app(EntitlementResolver::class)->forget($learner);

    return $subscription;
}

function goalLimiter(): DailyGoalLimiter
{
    return app(DailyGoalLimiter::class);
}

function goalGrade(Learner $learner, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 400, false, CarbonImmutable::now()));
    }
}

function goalTodayRow(Learner $learner): ?LearnerDailyStats
{
    return LearnerDailyStats::query()->where('learner_id', $learner->id)->first();
}

/** @return list<array<string, mixed>> */
function goalEvents(ProductEventName $name): array
{
    return ProductEvent::query()->where('name', $name->value)->orderBy('id')->get()->map(fn (ProductEvent $e): array => $e->props)->all();
}

/** How many statements `$call` runs. */
function goalStatements(callable $call): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $call();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

// --- cap / options / assertAllowed -----------------------------------------------------------------------------------

it('locks the goals above the Free limit and none on Pro', function (): void {
    $free = Entitlement::free();
    $pro = Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null);

    expect(goalLimiter()->cap($free))->toBe(10)
        ->and(goalLimiter()->cap($pro))->toBeNull()
        ->and(goalLimiter()->options($free))->toBe([
            ['value' => 10, 'locked' => false], ['value' => 20, 'locked' => true], ['value' => 40, 'locked' => true],
        ])
        ->and(goalLimiter()->options($pro))->toBe([
            ['value' => 10, 'locked' => false], ['value' => 20, 'locked' => false], ['value' => 40, 'locked' => false],
        ]);

    goalLimiter()->assertAllowed(10, $free);
    goalLimiter()->assertAllowed(40, $pro);

    try {
        goalLimiter()->assertAllowed(20, $free);
        $this->fail('Expected DailyGoalLimitException');
    } catch (DailyGoalLimitException $e) {
        expect($e->errorCode)->toBe('daily_goal_pro_required')
            ->and($e->httpStatus())->toBe(403)
            ->and($e->details)->toBe(['max' => 10]);
    }
});

it('fits a limit that is not an offered goal down to the nearest offered one', function (): void {
    config()->set('billing.plans.free.limits.daily_goal_max', 15);
    expect(goalLimiter()->cap(Entitlement::free()))->toBe(10);

    config()->set('billing.plans.free.limits.daily_goal_max', 5); // below every option: the limit itself
    expect(goalLimiter()->cap(Entitlement::free()))->toBe(5);
});

// --- enforce ---------------------------------------------------------------------------------------------------------

it('lowers 40 to 10 when Pro ends and gives 40 back when Pro returns, once each', function (): void {
    $learner = goalLearner();
    $limiter = goalLimiter();

    $change = $limiter->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

    expect($change)->toEqual(new DailyGoalChange(DailyGoalChange::LIMITED, 40, 10, false))
        ->and($learner->daily_goal)->toBe(10)
        ->and($learner->daily_goal_before_limit)->toBe(40)
        ->and($learner->isDirty())->toBeFalse()
        ->and($learner->fresh()->daily_goal)->toBe(10)
        ->and($learner->fresh()->daily_goal_before_limit)->toBe(40)
        ->and($limiter->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY))->toBeNull()
        // A copy of the model from before the lowering: the second call finds nothing to do under the lock.
        ->and($limiter->enforce(Learner::factory()->make(['id' => $learner->id, 'daily_goal' => 40]), Entitlement::free(), DailyGoalLimiter::VIA_TODAY))->toBeNull()
        ->and($learner->fresh()->daily_goal_before_limit)->toBe(40);

    $pro = Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null);
    $back = $limiter->enforce($learner, $pro, DailyGoalLimiter::VIA_SETTINGS);

    expect($back)->toEqual(new DailyGoalChange(DailyGoalChange::RESTORED, 10, 40, false))
        ->and($learner->fresh()->daily_goal)->toBe(40)
        ->and($learner->fresh()->daily_goal_before_limit)->toBeNull()
        ->and($limiter->enforce($learner, $pro, DailyGoalLimiter::VIA_SETTINGS))->toBeNull()
        ->and(goalEvents(ProductEventName::DailyGoalLimited))->toEqual([['from' => 40, 'to' => 10, 'via' => 'today']])
        ->and(goalEvents(ProductEventName::DailyGoalRestored))->toEqual([['from' => 10, 'to' => 40, 'via' => 'settings']]);
});

it('runs no statement when there is nothing to change', function (): void {
    $free10 = goalLearner(['daily_goal' => 10]);
    $pro40 = goalLearner(['daily_goal' => 40]);
    $lowered = goalLearner(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);
    $pro = Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null);

    expect(goalStatements(fn () => goalLimiter()->enforce($free10, Entitlement::free(), DailyGoalLimiter::VIA_TODAY)))->toBe(0)
        ->and(goalStatements(fn () => goalLimiter()->enforce($pro40, $pro, DailyGoalLimiter::VIA_TODAY)))->toBe(0)
        // A lowered learner who is still on Free: nothing to do either.
        ->and(goalStatements(fn () => goalLimiter()->enforce($lowered, Entitlement::free(), DailyGoalLimiter::VIA_TODAY)))->toBe(0);
});

it('keeps the learner\'s own choice when the limit goes down twice', function (): void {
    $learner = goalLearner();

    config()->set('billing.plans.free.limits.daily_goal_max', 20);
    goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_COMMAND);
    expect($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 20, 'daily_goal_before_limit' => 40]);

    config()->set('billing.plans.free.limits.daily_goal_max', 10);
    goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_COMMAND);
    expect($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);

    goalLimiter()->enforce($learner, Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null), DailyGoalLimiter::VIA_TODAY);
    expect($learner->fresh()->daily_goal)->toBe(40);
});

it('gives back the nearest lower offered goal when the remembered one is no longer offered', function (): void {
    $learner = goalLearner(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);
    config()->set('learners.daily_goal_options', [10, 20, 30]);

    goalLimiter()->enforce($learner, Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null), DailyGoalLimiter::VIA_TODAY);

    expect($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 30, 'daily_goal_before_limit' => null]);
});

it('does nothing on a snapshot whose plan has run out by the clock, nor for a deleted learner', function (): void {
    $learner = goalLearner();
    $stale = Entitlement::fromPlan(Plan::Free, Entitlement::SOURCE_FREE, CarbonImmutable::now()->subMinute(), null);

    expect(goalLimiter()->enforce($learner, $stale, DailyGoalLimiter::VIA_TODAY))->toBeNull();

    $copy = Learner::query()->findOrFail($learner->id);
    $learner->delete();
    expect(goalLimiter()->enforce($copy, Entitlement::free(), DailyGoalLimiter::VIA_TODAY))->toBeNull()
        ->and(Learner::withTrashed()->findOrFail($learner->id)->daily_goal)->toBe(40);
});

it('completes today\'s goal and extends the streak exactly once when the lowering reaches what is done', function (): void {
    $learner = goalLearner(['streak_current' => 3, 'streak_best' => 3, 'streak_last_date' => '2026-11-24']);
    goalGrade($learner, 12);
    expect(goalTodayRow($learner)->goal_met_at)->toBeNull();

    $change = goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

    expect($change?->goalMet)->toBeTrue()
        ->and(goalTodayRow($learner)->goal_met_at?->toDateTimeString())->toBe('2026-11-25 10:00:00')
        ->and($learner->fresh()->streak_current)->toBe(4)
        ->and($learner->streak_current)->toBe(4);

    // Later grades, another enforce, a restore and a second lowering do not credit the day again.
    goalGrade($learner->fresh(), 5);
    goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);
    goalLimiter()->enforce($learner, Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, null), DailyGoalLimiter::VIA_TODAY);
    goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

    expect($learner->fresh()->streak_current)->toBe(4)
        ->and(goalEvents(ProductEventName::DailyGoalMet))->toHaveCount(1)
        ->and(goalEvents(ProductEventName::StreakExtended))->toHaveCount(1);
});

it('does not complete the goal when less than the new goal is done', function (): void {
    $learner = goalLearner();
    goalGrade($learner, 7);

    $change = goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

    expect($change?->goalMet)->toBeFalse()
        ->and(goalTodayRow($learner)->goal_met_at)->toBeNull()
        ->and($learner->fresh()->streak_current)->toBe(0);

    // The 10th card of the day completes it through the review path, which reads the lowered goal.
    goalGrade($learner->fresh(), 3);
    expect(goalTodayRow($learner)->goal_met_at)->not->toBeNull()
        ->and($learner->fresh()->streak_current)->toBe(1);
});

it('keeps the lowered goal and only reports when the reconcile after the commit fails', function (): void {
    Exceptions::fake();
    $learner = goalLearner();
    goalGrade($learner, 12);
    $this->mock(DailyStatsService::class)->shouldReceive('reconcileGoal')->once()->andThrow(new RuntimeException('deadlock'));
    app()->forgetInstance(DailyGoalLimiter::class);

    $change = app(DailyGoalLimiter::class)->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);

    expect($change?->to)->toBe(10)
        ->and($change?->goalMet)->toBeFalse()
        ->and($learner->fresh()->daily_goal)->toBe(10);
    Exceptions::assertReported(RuntimeException::class);
});

// --- the screens -----------------------------------------------------------------------------------------------------

it('lowers the goal of a learner whose trial ended on the first open of Today and gives it back after a purchase', function (): void {
    $learner = goalLearner();
    goalPro($learner, CarbonImmutable::parse('2026-11-26 08:00:00'));

    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/today')->assertOk()->assertJsonPath('goal.target', 40);
    expect($learner->fresh()->daily_goal)->toBe(40);

    CarbonImmutable::setTestNow('2026-11-26 09:00:00'); // the trial is over by the clock, `subscriptions:expire` has not run
    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/today')->assertOk()->assertJsonPath('goal.target', 10);
    expect($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 40])
        ->and(goalEvents(ProductEventName::DailyGoalLimited))->toEqual([['from' => 40, 'to' => 10, 'via' => 'today']]);

    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('settings.daily_goal', 10)
        ->assertJsonPath('settings.options.daily_goal_max', 10)
        ->assertJsonPath('settings.options.daily_goals', [10, 20, 40]);

    // A purchase: the trial row is swept, a paid one starts.
    Subscription::query()->where('subscriber_id', $learner->id)->update(['status' => 'expired', 'ended_at' => CarbonImmutable::now()]);
    goalPro($learner, CarbonImmutable::parse('2026-12-26 09:00:00'), 'payme');
    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/me')->assertOk()
        ->assertJsonPath('settings.daily_goal', 40)
        ->assertJsonPath('settings.options.daily_goal_max', null);
    expect($learner->fresh()->daily_goal_before_limit)->toBeNull();
});

it('completes the day on Today when the lowering reaches what is done', function (): void {
    $learner = goalLearner();
    goalGrade($learner, 11);

    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/today')->assertOk()
        ->assertJsonPath('goal.target', 10)
        ->assertJsonPath('goal.done', 11)
        ->assertJsonPath('goal.met', true)
        ->assertJsonPath('streak.current', 1)
        ->assertJsonPath('streak.met_today', true);
});

it('sizes the daily round by the lowered goal', function (): void {
    config()->set('learning.sprint.enabled', false);
    $learner = goalLearner();

    $this->withHeaders(goalHeaders($learner))->postJson('/api/twa/training/sessions', ['mode' => 'daily_review'])->assertOk()
        ->assertJsonPath('total_words', 10);
    expect($learner->fresh()->daily_goal)->toBe(10)
        ->and(goalEvents(ProductEventName::DailyGoalLimited))->toEqual([['from' => 40, 'to' => 10, 'via' => 'training']]);
});

// --- PATCH /me/settings ----------------------------------------------------------------------------------------------

it('refuses a goal above the Free limit with 403 daily_goal_pro_required and applies nothing of the request', function (): void {
    $learner = goalLearner(['daily_goal' => 10, 'reminder_time' => '19:00']);

    foreach ([20, 40] as $goal) {
        $this->withHeaders(goalHeaders($learner))
            ->patchJson('/api/twa/me/settings', ['daily_goal' => $goal, 'reminder_time' => '21:00'])
            ->assertStatus(403)
            ->assertExactJson(['error' => [
                'code' => 'daily_goal_pro_required',
                'message' => "A daily goal of {$goal} cards needs Pro; the plan allows at most 10.",
                'details' => ['max' => 10],
            ]]);
    }

    expect($learner->fresh()->daily_goal)->toBe(10)
        ->and(substr((string) $learner->fresh()->reminder_time, 0, 5))->toBe('19:00')
        ->and(ProductEvent::query()->where('name', ProductEventName::SettingsChanged->value)->count())->toBe(0);

    $this->withHeaders(goalHeaders($learner))->patchJson('/api/twa/me/settings', ['daily_goal' => 10])->assertOk();
});

it('accepts 20 and 40 from a Pro learner and from a learner seated in a center', function (): void {
    $own = goalLearner(['daily_goal' => 10]);
    goalPro($own, CarbonImmutable::now()->addDays(10));

    $org = Organization::factory()->create();
    Subscription::factory()->forOrganization($org)->create(['current_period_ends_at' => CarbonImmutable::now()->addDays(30)]);
    $center = Learner::factory()->onboarded()->seatedIn($org)->create(['daily_goal' => 10]);

    foreach ([$own, $center] as $learner) {
        $this->withHeaders(goalHeaders($learner))->patchJson('/api/twa/me/settings', ['daily_goal' => 40])->assertOk()
            ->assertJsonPath('settings.daily_goal', 40)
            ->assertJsonPath('settings.options.daily_goal_max', null);
        $this->withHeaders(goalHeaders($learner))->patchJson('/api/twa/me/settings', ['daily_goal' => 20])->assertOk()
            ->assertJsonPath('settings.daily_goal', 20);
    }
});

it('answers 422 for a goal that is not offered, on Free and on Pro alike', function (): void {
    $free = goalLearner(['daily_goal' => 10]);
    $pro = goalLearner(['daily_goal' => 10]);
    goalPro($pro, CarbonImmutable::now()->addDays(10));

    foreach ([$free, $pro] as $learner) {
        foreach ([25, 0, '20', 100] as $goal) {
            $this->withHeaders(goalHeaders($learner))->patchJson('/api/twa/me/settings', ['daily_goal' => $goal])
                ->assertStatus(422)->assertJsonValidationErrors('daily_goal');
        }
    }
});

it('forgets the remembered goal once the learner picks one by hand, even the same value', function (): void {
    $learner = goalLearner();
    goalLimiter()->enforce($learner, Entitlement::free(), DailyGoalLimiter::VIA_TODAY);
    expect($learner->fresh()->daily_goal_before_limit)->toBe(40);

    $this->withHeaders(goalHeaders($learner))->patchJson('/api/twa/me/settings', ['daily_goal' => 10])->assertOk();
    expect($learner->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => null]);

    // Pro comes back: the goal stays the one picked by hand.
    goalPro($learner, CarbonImmutable::now()->addDays(30), 'payme');
    $this->withHeaders(goalHeaders($learner))->getJson('/api/twa/me')->assertOk()->assertJsonPath('settings.daily_goal', 10);

    // The service path of the bot / other callers clears it the same way; another field leaves it alone.
    $other = goalLearner(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);
    app(LearnerSettingsService::class)->update($other, ['reminder_time' => '08:00']);
    expect($other->fresh()->daily_goal_before_limit)->toBe(40);
    app(LearnerSettingsService::class)->update($other, ['daily_goal' => 10]);
    expect($other->fresh()->daily_goal_before_limit)->toBeNull();
});

// --- the nightly command ---------------------------------------------------------------------------------------------

it('lowers, in the nightly sweep, only the goals above the plan of each learner and changes nothing on a second run', function (): void {
    $free40 = goalLearner();
    $free20 = goalLearner(['daily_goal' => 20]);
    $free10 = goalLearner(['daily_goal' => 10]);
    $pro40 = goalLearner();
    goalPro($pro40, CarbonImmutable::now()->addDays(5));
    $lowered = goalLearner(['daily_goal' => 10, 'daily_goal_before_limit' => 40]);
    $trialEnded = goalLearner();
    goalPro($trialEnded, CarbonImmutable::now()->subHour());
    $deleted = goalLearner();
    $deleted->delete();

    $this->artisan('learners:enforce-goal-limits')
        ->expectsOutput('Daily goals checked: 4, lowered: 3.')
        ->assertSuccessful();

    expect($free40->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 40])
        ->and($free20->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 20])
        ->and($trialEnded->fresh()->daily_goal)->toBe(10)
        ->and($free10->fresh()->daily_goal_before_limit)->toBeNull()
        ->and($pro40->fresh()->daily_goal)->toBe(40)
        ->and($lowered->fresh()->only(['daily_goal', 'daily_goal_before_limit']))->toBe(['daily_goal' => 10, 'daily_goal_before_limit' => 40])
        ->and(Learner::withTrashed()->findOrFail($deleted->id)->daily_goal)->toBe(40)
        ->and(collect(goalEvents(ProductEventName::DailyGoalLimited))->pluck('via')->unique()->all())->toBe(['command']);

    $this->artisan('learners:enforce-goal-limits')->expectsOutput('Daily goals checked: 1, lowered: 0.')->assertSuccessful();
});

it('schedules the sweep daily at 03:00 UTC without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'learners:enforce-goal-limits'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->timezone ?? 'UTC')->toBe('UTC');
});
