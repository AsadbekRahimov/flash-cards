<?php

declare(strict_types=1);

use App\Domain\Billing\DTO\Entitlement;
use App\Domain\Billing\Enums\Plan;
use App\Domain\Practice\DTO\PracticeLimitState;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\PracticeLimits;
use App\Models\Course;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §3 (G1), docs/16 §3.2-§3.3: the Free limits of the day. A learner without the feature `practice.unlimited` opens at most
 * `practice.free_limits.sessions_per_day` solo sessions and calls at most `practice.free_limits.battles_per_day` battles per LOCAL day
 * (the window of the quota of new words); a retry of the mistakes is free, a replacement counts, a rematch counts for the one who
 * offers it, accepting is always free. Refused: 403 `practice_limit_reached` with `{kind, limit, used, resets_at}` and the event
 * `practice.limit_reached`. The block `limits` in /me, the hub and the 201s. The races for the last place: PracticeLimitsConcurrencyTest.
 */

uses(RefreshDatabase::class);

/** 10:00 UTC = 15:00 in Asia/Tashkent, the default zone of a learner: the local day ends at 19:00 UTC. */
const LIMITS_T0 = '2026-10-30 10:00:00';
const LIMITS_RESET = '2026-10-30T19:00:00.000Z';
const LIMITS_BLOCK_SHAPE = ['sessions' => ['limit', 'used', 'remaining', 'resets_at'], 'battles' => ['limit', 'used', 'remaining', 'resets_at']];

beforeEach(function (): void {
    CarbonImmutable::setTestNow(LIMITS_T0);
    PracticeKit::openAccess();
    PracticeKit::limits(2, 1);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 8);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** Opens a collocation session, replacing the unfinished one unless told otherwise. @param array<string, mixed> $body */
function limitsOpen(Learner $learner, string $course, array $body = []): TestResponse
{
    PracticeKit::resetLimiters($learner);

    return test()->withHeaders(PracticeKit::headers($learner))
        ->postJson('/api/twa/practice/sessions', ['mode' => 'collocation', 'course' => $course, 'replace_active' => true, ...$body]);
}

function limitsMe(Learner $learner): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me');
}

function limitsHub(Learner $learner, string $course): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson("/api/twa/practice/hub?course={$course}");
}

/** The props of every `practice.limit_reached` event, in order. @return list<array<string, mixed>> */
function limitsEvents(): array
{
    return ProductEvent::query()->where('name', 'practice.limit_reached')->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** @return array{limit: int, used: int, remaining: int, resets_at: string} */
function limitsBlock(int $limit, int $used, string $resetsAt = LIMITS_RESET): array
{
    return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'resets_at' => $resetsAt];
}

/** Plays a REAL sentence session to its end with a mistake in every element (a skip), so that a retry is available. @return array<string, mixed> */
function limitsFinished(Learner $learner, Course $course, Lesson $lesson): array
{
    $session = limitsOpen($learner, $course->slug, ['mode' => 'sentence', 'lesson_id' => $lesson->id])->assertCreated()->json();
    for ($i = 0; $session['status'] === 'active' && $i < 10; $i++) {
        PracticeKit::resetLimiters($learner);
        $session = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$session['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $session['current']['id'], 'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    expect($session['status'])->toBe('finished');

    return $session;
}

// ---------------------------------------------------------------- sessions

it('lets a Free learner open two sessions a day and refuses the third with 403 practice_limit_reached, the details and the event', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();

    $first = limitsOpen($learner, $course->slug)->assertCreated();
    expect($first->json('limits'))->toBe(['sessions' => limitsBlock(2, 1), 'battles' => limitsBlock(1, 0)]);

    // A session that replaces the unfinished one is a new session: it counts.
    $second = limitsOpen($learner, $course->slug)->assertCreated();
    expect($second->json('limits.sessions'))->toBe(limitsBlock(2, 2));

    $third = limitsOpen($learner, $course->slug)->assertForbidden();
    expect($third->json('error.code'))->toBe('practice_limit_reached')
        ->and($third->json('error.details'))->toBe(['kind' => 'sessions', 'limit' => 2, 'used' => 2, 'resets_at' => LIMITS_RESET])
        ->and($third->json('error.message'))->toBeString()->toContain('2')
        ->and(PracticeSession::query()->where('learner_id', $learner->id)->count())->toBe(2)
        ->and(PracticeSession::query()->where('active_learner_id', $learner->id)->count())->toBe(1) // the second is still on
        ->and(limitsEvents())->toBe([['kind' => 'sessions', 'limit' => 2]]);

    // The refusal is repeated, and recorded each time.
    limitsOpen($learner, $course->slug)->assertForbidden();
    expect(limitsEvents())->toHaveCount(2);
});

it('does not spend the limit on a retry of the mistakes, and lets a retry through even when sessions are for Pro only', function (): void {
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = PracticeKit::words($lesson, 3, $learner);
    foreach (range(1, 5) as $n) {
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
    }

    $finished = limitsFinished($learner, $course, $lesson); // the first session of the day
    expect($finished['summary']['retry_available'])->toBeTrue();

    $retry = limitsOpen($learner, $course->slug, ['retry_of' => $finished['id'], 'source' => 'retry'])->assertCreated();
    expect($retry->json('retry_of'))->toBe($finished['id'])
        ->and($retry->json('limits.sessions'))->toBe(limitsBlock(2, 1)); // still one: the retry is free

    // The retry itself, played with mistakes, can be retried - and the limit does not even look at it when sessions are for Pro only.
    $retried = $retry->json();
    for ($i = 0; $retried['status'] === 'active' && $i < 10; $i++) {
        PracticeKit::resetLimiters($learner);
        $retried = test()->withHeaders(PracticeKit::headers($learner))->postJson("/api/twa/practice/sessions/{$retried['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $retried['current']['id'], 'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    PracticeKit::limits(0, 1);
    limitsOpen($learner, $course->slug, ['retry_of' => $retried['id'], 'source' => 'retry'])->assertCreated()
        ->assertJsonPath('limits.sessions', limitsBlock(0, 1));
    limitsOpen($learner, $course->slug)->assertForbidden()->assertJsonPath('error.details.kind', 'sessions');
    expect(limitsEvents())->toBe([['kind' => 'sessions', 'limit' => 0]]);
});

it('counts by the LOCAL day of the learner: the window closes at their midnight, resets_at names it, another zone has another day', function (): void {
    [$course] = PracticeKit::course();
    $tashkent = Learner::factory()->create(['timezone' => 'Asia/Tashkent']);

    // 23:30 local on the 30th: both places of the day are taken ...
    CarbonImmutable::setTestNow('2026-10-30 18:30:00');
    limitsOpen($tashkent, $course->slug)->assertCreated();
    limitsOpen($tashkent, $course->slug)->assertCreated();
    limitsOpen($tashkent, $course->slug)->assertForbidden()->assertJsonPath('error.details.resets_at', '2026-10-30T19:00:00.000Z');

    // ... and at 00:00 local the day is new: the sessions of yesterday are not counted.
    CarbonImmutable::setTestNow('2026-10-30 19:00:00');
    limitsOpen($tashkent, $course->slug)->assertCreated()->assertJsonPath('limits.sessions', limitsBlock(2, 1, '2026-10-31T19:00:00.000Z'));
    expect(limitsMe($tashkent)->json('practice.limits.sessions'))->toBe(limitsBlock(2, 1, '2026-10-31T19:00:00.000Z'));

    // The same instants are another day for a learner in London (GMT at the end of October): 18:30 UTC is 18:30 there, the day ends at midnight UTC.
    $london = Learner::factory()->create(['timezone' => 'Europe/London']);
    CarbonImmutable::setTestNow('2026-10-30 18:30:00');
    limitsOpen($london, $course->slug)->assertCreated();
    CarbonImmutable::setTestNow('2026-10-30 19:00:00');
    limitsOpen($london, $course->slug)->assertCreated()->assertJsonPath('limits.sessions', limitsBlock(2, 2, '2026-10-31T00:00:00.000Z'));
    CarbonImmutable::setTestNow('2026-10-30 23:59:59');
    limitsOpen($london, $course->slug)->assertForbidden()->assertJsonPath('error.details.resets_at', '2026-10-31T00:00:00.000Z');
    CarbonImmutable::setTestNow('2026-10-31 00:00:00');
    limitsOpen($london, $course->slug)->assertCreated()->assertJsonPath('limits.sessions.used', 1);
});

it('refuses Free altogether at a limit of 0 - the kind is for Pro only - and never limits Pro, sponsored or not', function (): void {
    PracticeKit::limits(0, 0);
    [$course] = PracticeKit::course();
    $free = Learner::factory()->create();

    $refused = limitsOpen($free, $course->slug)->assertForbidden();
    expect($refused->json('error.details'))->toBe(['kind' => 'sessions', 'limit' => 0, 'used' => 0, 'resets_at' => LIMITS_RESET])
        ->and(limitsMe($free)->json('practice.limits'))->toBe(['sessions' => limitsBlock(0, 0), 'battles' => limitsBlock(0, 0)]);

    $pro = PracticeKit::pro(Learner::factory()->create());
    foreach (range(1, 3) as $n) {
        limitsOpen($pro, $course->slug)->assertCreated()->assertJsonPath('limits', null);
    }
    expect(limitsMe($pro)->json('practice.limits'))->toBeNull()
        ->and(limitsHub($pro, $course->slug)->json('limits'))->toBeNull()
        ->and(PracticeSession::query()->where('learner_id', $pro->id)->count())->toBe(3)
        ->and(limitsEvents())->toBe([['kind' => 'sessions', 'limit' => 0]]);

    // The feature, not the plan's name, decides: the sponsored Pro of a center has it like a subscription of one's own.
    $limits = app(PracticeLimits::class);
    expect($limits->isUnlimited(Entitlement::fromPlan(Plan::Pro, Entitlement::sponsorSource(7), null, null)))->toBeTrue()
        ->and($limits->isUnlimited(Entitlement::fromPlan(Plan::Pro, Entitlement::SOURCE_SUBSCRIPTION, null, 3)))->toBeTrue()
        ->and($limits->isUnlimited(Entitlement::free()))->toBeFalse();
});

it('keeps PRACTICE_PLAN=pro as it was: a Free learner gets 403 practice_pro_required, never the limit, and no limits block', function (): void {
    config(['practice.plan' => 'pro']);
    PracticeKit::limits(0, 0);
    [$course] = PracticeKit::course();
    $free = Learner::factory()->create();

    limitsOpen($free, $course->slug)->assertForbidden()->assertJsonPath('error.code', 'practice_pro_required');
    expect(limitsMe($free)->json('practice'))->toMatchArray(['available' => true, 'pro_required' => true, 'limits' => null])
        ->and(limitsHub($free, $course->slug)->assertOk()->json('limits'))->toBeNull()
        ->and(limitsEvents())->toBe([]);
});

it('checks the limit after the access and the switch of the mode, and before the course, the material and the unfinished session', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    limitsOpen($learner, $course->slug)->assertCreated();
    limitsOpen($learner, $course->slug)->assertCreated();

    // At the limit: an unknown course, a mode without material and an unfinished session are not even looked at.
    limitsOpen($learner, 'no-such-course')->assertForbidden()->assertJsonPath('error.code', 'practice_limit_reached');
    limitsOpen($learner, $course->slug, ['mode' => 'sentence'])->assertForbidden()->assertJsonPath('error.code', 'practice_limit_reached');
    limitsOpen($learner, $course->slug, ['replace_active' => false])->assertForbidden()->assertJsonPath('error.code', 'practice_limit_reached');

    // The switch of the mode comes before the limit, the access before everything.
    config(['practice.modes.collocation' => false]);
    limitsOpen($learner, $course->slug)->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled');
    config(['practice.access' => 'off']);
    limitsOpen($learner, $course->slug)->assertForbidden()->assertJsonPath('error.code', 'practice_unavailable');

    // The unfinished session is still there to be played.
    expect(PracticeSession::query()->where('active_learner_id', $learner->id)->count())->toBe(1)
        ->and(limitsEvents())->toHaveCount(3);
});

// ---------------------------------------------------------------- /me and the hub

it('shows the limits of the day in /me and in the hub: the exact shape for Free, null for Pro, for a kind without a limit and while the practice is closed', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $fresh = ['sessions' => limitsBlock(2, 0), 'battles' => limitsBlock(1, 0)];

    limitsMe($learner)->assertOk()->assertJsonStructure(['practice' => ['limits' => LIMITS_BLOCK_SHAPE]])->assertJsonPath('practice.limits', $fresh);
    limitsHub($learner, $course->slug)->assertOk()->assertJsonStructure(['limits' => LIMITS_BLOCK_SHAPE])->assertJsonPath('limits', $fresh);

    limitsOpen($learner, $course->slug)->assertCreated();
    expect(limitsMe($learner)->json('practice.limits.sessions'))->toBe(limitsBlock(2, 1))
        ->and(limitsHub($learner, $course->slug)->json('limits.sessions'))->toBe(limitsBlock(2, 1));

    // A kind without a limit (an empty value) is null inside the block; both empty - the block is null, as for Pro.
    PracticeKit::limits(2, null);
    expect(limitsMe($learner)->json('practice.limits'))->toBe(['sessions' => limitsBlock(2, 1), 'battles' => null]);
    PracticeKit::limits(null, null);
    expect(limitsMe($learner)->json('practice.limits'))->toBeNull()
        ->and(limitsHub($learner, $course->slug)->json('limits'))->toBeNull();

    // Closed to the learner (not in the pilot): nothing to count.
    PracticeKit::limits(2, 1);
    config(['practice.access' => 'pilot']);
    expect(limitsMe($learner)->json('practice'))->toMatchArray(['available' => false, 'limits' => null]);
});

it('spends one statement on the limits of a Free learner in /me and the hub, four on an opening, and none on Pro', function (): void {
    [$course] = PracticeKit::course();
    $learner = Learner::factory()->create();
    limitsMe($learner)->assertOk(); // warms the cache of the entitlement, the way any first request does

    $me = QueryLog::during(fn () => limitsMe($learner)->assertOk());
    $hub = QueryLog::during(fn () => limitsHub($learner, $course->slug)->assertOk());
    $open = QueryLog::during(fn () => limitsOpen($learner, $course->slug)->assertCreated());

    PracticeKit::limits(null, null);
    PracticeSession::query()->delete(); // like with like: an opening that replaces nothing
    $meFree = QueryLog::during(fn () => limitsMe($learner)->assertOk());
    $hubFree = QueryLog::during(fn () => limitsHub($learner, $course->slug)->assertOk());
    $openFree = QueryLog::during(fn () => limitsOpen($learner, $course->slug)->assertCreated());

    // The block: one statement (both counts in one); the opening: the check before the work, the lock of the learner's row, the count
    // under it, and the block of the 201.
    expect(count($me) - count($meFree))->toBe(1, implode("\n", $me))
        ->and(count($hub) - count($hubFree))->toBe(1, implode("\n", $hub))
        ->and(count($open) - count($openFree))->toBe(4, implode("\n", $open))
        ->and(count($hub))->toBeLessThanOrEqual(10)
        ->and(count($open))->toBeLessThanOrEqual(16);

    PracticeKit::limits(2, 1);
    $pro = PracticeKit::pro(Learner::factory()->create());
    limitsMe($pro)->assertOk();
    $mePro = QueryLog::during(fn () => limitsMe($pro)->assertOk()->assertJsonPath('practice.limits', null));
    $hubPro = QueryLog::during(fn () => limitsHub($pro, $course->slug)->assertOk()->assertJsonPath('limits', null));
    $openPro = QueryLog::during(fn () => limitsOpen($pro, $course->slug)->assertCreated()->assertJsonPath('limits', null));
    // Pro pays nothing for the limits: no count of its sessions or battles in /me and the hub (the hub reads `battles` once for its own
    // summary, `practice_sessions` once for the unfinished session), no lock of its row and no count on an opening.
    $limitStatements = '/count\(\*\).{0,30}from "?(practice_sessions|battles)|select "id" from "learners"/';
    expect(QueryLog::matching($mePro, $limitStatements))->toBe([])
        ->and(QueryLog::matching($hubPro, $limitStatements))->toBe([])
        ->and(QueryLog::matching($openPro, $limitStatements))->toBe([])
        ->and(QueryLog::matching($open, $limitStatements))->toHaveCount(4) // the Free opening above: the check, the lock, the count, the block
        ->and(count($openPro))->toBe(count($openFree));
});

// ---------------------------------------------------------------- battles

it('lets a Free learner call one battle a day, refuses the second call with kind battles, and lets a friend at their limit accept', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    PracticeKit::limits(2, 1);

    $first = BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course, $lesson)->assertCreated();
    expect($first->json('limits'))->toBe(['sessions' => limitsBlock(2, 0), 'battles' => limitsBlock(1, 1)]);

    // A call that was withdrawn still counts: it was created.
    BattleHttp::cancel($me, (int) $first->json('id'))->assertOk();
    BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course, $lesson)->assertForbidden()
        ->assertJsonPath('error.code', 'practice_limit_reached')
        ->assertJsonPath('error.details', ['kind' => 'battles', 'limit' => 1, 'used' => 1, 'resets_at' => LIMITS_RESET]);
    expect(limitsEvents())->toBe([['kind' => 'battles', 'limit' => 1]]);

    // The friend spends their call on somebody else ...
    $third = Learner::factory()->create();
    $withThird = Friendship::factory()->between($friend, $third)->create();
    $spent = BattleHttp::invite($friend, $withThird, PracticeMode::Collocation, $course)->assertCreated();
    BattleHttp::cancel($friend, (int) $spent->json('id'))->assertOk();
    BattleHttp::invite($friend, $friendship, PracticeMode::Collocation, $course)->assertForbidden()->assertJsonPath('error.details.kind', 'battles');

    // ... and may still ACCEPT: a Pro friend brings Free friends in.
    PracticeKit::pro($me);
    $invite = BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course, $lesson)->assertCreated()->assertJsonPath('limits', null);
    BattleHttp::accept($friend, (int) $invite->json('id'))->assertOk()->assertJsonPath('status', 'room');
    expect(limitsMe($friend)->json('practice.limits.battles'))->toBe(limitsBlock(1, 1));
});

it('counts a rematch for the one who offers it', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    PracticeKit::limits(null, 1);

    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson); // my call of the day
    BattleKit::service()->surrender($me, $battle->id);

    $rematch = BattleHttp::rematch($friend, $battle->id)->assertCreated(); // the friend's first call
    expect($rematch->json('limits'))->toBe(['sessions' => null, 'battles' => limitsBlock(1, 1)]);

    BattleHttp::cancel($friend, (int) $rematch->json('id'))->assertOk();
    BattleHttp::rematch($friend, $battle->id)->assertForbidden()->assertJsonPath('error.details.kind', 'battles');
    BattleHttp::rematch($me, $battle->id)->assertForbidden()->assertJsonPath('error.details.kind', 'battles'); // mine was the first call
    expect(limitsMe($me)->json('practice.limits.battles.used'))->toBe(1)
        ->and(limitsMe($friend)->json('practice.limits.battles.used'))->toBe(1)
        ->and(limitsEvents())->toBe([['kind' => 'battles', 'limit' => 1], ['kind' => 'battles', 'limit' => 1]]);
});

it('checks the limit of a battle after the switches and before the friend, the course and the material', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    PracticeKit::limits(null, 0);

    BattleHttp::post($me, '', ['friendship_id' => 424242, 'mode' => 'collocation', 'course' => 'no-such-course'])->assertForbidden()
        ->assertJsonPath('error.code', 'practice_limit_reached')->assertJsonPath('error.details', ['kind' => 'battles', 'limit' => 0, 'used' => 0, 'resets_at' => LIMITS_RESET]);
    config(['practice.modes.collocation' => false]);
    BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course)->assertStatus(409)->assertJsonPath('error.code', 'practice_mode_disabled');
    config(['practice.battle.enabled' => false]);
    BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course)->assertStatus(409)->assertJsonPath('error.code', 'battle_disabled');
    config(['practice.access' => 'off']);
    BattleHttp::invite($me, $friendship, PracticeMode::Collocation, $course)->assertForbidden()->assertJsonPath('error.code', 'practice_unavailable');
});

// ---------------------------------------------------------------- the exception

it('is a 403 with the details of docs/16 §7.6, and a message with the number', function (): void {
    $state = new PracticeLimitState('battles', 1, 1, CarbonImmutable::parse('2026-10-30 19:00:00'));
    $e = PracticeException::limitReached($state);

    expect($e->errorCode)->toBe('practice_limit_reached')
        ->and($e->httpStatus())->toBe(403)
        ->and($e->details)->toBe(['kind' => 'battles', 'limit' => 1, 'used' => 1, 'resets_at' => LIMITS_RESET])
        ->and($e->getMessage())->toContain('1')
        ->and($state->remaining())->toBe(0)
        ->and($state->isReached())->toBeTrue()
        ->and((new PracticeLimitState('sessions', 2, 5, $state->resetsAt))->remaining())->toBe(0);
});
