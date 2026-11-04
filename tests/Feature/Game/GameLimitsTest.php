<?php

declare(strict_types=1);

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Game\DTO\PendingRunClaim;
use App\Domain\Game\Enums\FreeSlot;
use App\Domain\Game\Enums\RunFinishReason;
use App\Domain\Game\Enums\RunKind;
use App\Domain\Game\Enums\TicketSource;
use App\Domain\Game\Exceptions\GameException;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Game\Services\GameLimits;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\GameBoosterUse;
use App\Models\GameItem;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameTicketLedgerEntry;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §3, §12.7.2, §12.7.4 (G2.8): the Free limits of the game. The free run of the day, the ticket beyond it, the 403 with its
 * `details`, a run closed without an answer gives its place back, Pro without a limit (no lock, no ticket), the bosses of the first
 * cities, the boosters of the week, the window carried across a change of timezone, the block `/me.game.limits`, the event
 * `game.limit_reached`. The races for the last place and the last ticket are GameRunConcurrencyTest's (PostgreSQL).
 */

uses(RefreshDatabase::class);

/** 2026-11-05 10:00 UTC is 15:00 in Tashkent (the zone of the test learners, UTC+5): the day ends at 19:00Z, the week on Monday the 9th. */
const LIMITS_DAY_END = '2026-11-05T19:00:00.000Z';

const LIMITS_WEEK_END = '2026-11-08T19:00:00.000Z';

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
    GameKit::limits(1, 1, 1); // the shipped Free limits (docs/18 §12.7.1)
});

afterEach(fn () => CarbonImmutable::setTestNow());

function gameLimitsStart(Learner $learner, string $course, int $lessonId, bool $replace = false): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->postJson('/api/twa/game/runs', ['kind' => 'stop', 'course' => $course, 'lesson_id' => $lessonId, 'replace_active' => $replace]);
}

/** @return array<string, mixed>|null the block `game.limits` of GET /me */
function gameLimitsMe(Learner $learner): ?array
{
    return test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk()->json('game.limits');
}

/** @return array{limit: int, used: int, remaining: int, resets_at: string} */
function gameLimitsBlock(int $limit, int $used, string $resetsAt): array
{
    return ['limit' => $limit, 'used' => $used, 'remaining' => max(0, $limit - $used), 'resets_at' => $resetsAt];
}

/** @return list<array<string, mixed>> */
function gameLimitsEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** A run of the stop played to its end: the place of the day is taken for good. */
function gameLimitsPlayed(Learner $learner, string $course, int $lessonId): GameRun
{
    $runs = app(RunService::class);

    return GameKit::play($runs, $runs->start($learner, $course, $lessonId), $learner);
}

// ---------------------------------------------------------------- the block of /me

it('tells the Free learner the shipped limits in /me.game.limits: nothing used, the day and the week ahead', function (): void {
    [$learner] = GameKit::stop(8);

    expect(gameLimitsMe($learner))->toBe([
        'runs' => gameLimitsBlock(1, 0, LIMITS_DAY_END),
        'tickets' => ['balance' => 0, 'max' => 10],
        'boosters' => gameLimitsBlock(1, 0, LIMITS_WEEK_END),
        'boss_stages' => 1,
    ]);
});

it('says null for a limit the config leaves empty and keeps 0 as a limit (only Pro), for every kind', function (): void {
    [$learner] = GameKit::stop(8);

    GameKit::limits(null, null, 0);
    config(['game.free_limits.boss_stages' => '']); // the empty value of an env
    $block = gameLimitsMe($learner);
    expect($block['runs'])->toBeNull()
        ->and($block['boss_stages'])->toBeNull()
        ->and($block['boosters'])->toBe(gameLimitsBlock(0, 0, LIMITS_WEEK_END))
        ->and($block['tickets'])->toBe(['balance' => 0, 'max' => 10]);

    GameKit::limits(0, 0, null);
    $block = gameLimitsMe($learner);
    expect($block['runs'])->toBe(gameLimitsBlock(0, 0, LIMITS_DAY_END))
        ->and($block['boss_stages'])->toBe(0)
        ->and($block['boosters'])->toBeNull();
});

it('says null to Pro where Pro has no limit, counts the boosters of the Pro plan, and keeps the tickets', function (): void {
    [$learner] = GameKit::stop(8);
    GameProfile::factory()->withTickets(3)->create(['learner_id' => $learner->id]);
    PracticeKit::pro($learner);

    expect(gameLimitsMe($learner))->toBe([
        'runs' => null,
        'tickets' => ['balance' => 3, 'max' => 10],
        'boosters' => gameLimitsBlock(5, 0, LIMITS_WEEK_END),
        'boss_stages' => null,
    ]);
});

it('is null as a whole while the game is closed to the learner', function (): void {
    [$learner] = GameKit::stop(8);

    config(['game.access' => 'off']);
    expect(gameLimitsMe($learner))->toBeNull();

    config(['game.access' => 'pilot']);
    expect(gameLimitsMe($learner))->toBeNull();
});

it('reads the whole block in one statement, whatever the plan', function (): void {
    [$learner] = GameKit::stop(8);
    $limits = app(GameLimits::class);
    $entitlement = app(EntitlementResolver::class)->for($learner);

    expect(QueryLog::during(fn () => $limits->state($learner, $entitlement)))->toHaveCount(1);

    PracticeKit::pro($learner);
    $entitlement = app(EntitlementResolver::class)->for($learner);
    expect(QueryLog::during(fn () => $limits->state($learner, $entitlement)))->toHaveCount(1);
});

// ---------------------------------------------------------------- the free run of the day and the ticket

it('gives the Free learner one free map run a day, then refuses with 403 game_limit_reached and the details of docs/18 §12.7.2', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);

    $first = gameLimitsPlayed($learner, $course->slug, $lesson->id);
    expect($first->free_slot)->toBe(FreeSlot::Daily)
        ->and(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(1, 1, LIMITS_DAY_END));

    $refused = gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden();
    expect($refused->json('error.code'))->toBe('game_limit_reached')
        ->and($refused->json('error.details'))->toBe(['kind' => 'runs', 'limit' => 1, 'used' => 1, 'tickets' => 0, 'resets_at' => LIMITS_DAY_END])
        ->and(GameRun::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(gameLimitsEvents('game.limit_reached'))->toBe([['kind' => 'runs', 'limit' => 1]])
        ->and(gameLimitsEvents('game.run_started'))->toHaveCount(1);
});

it('refuses on the courtesy check before the material is read: no word of the lesson is touched by a refused start', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    gameLimitsPlayed($learner, $course->slug, $lesson->id);
    gameLimitsMe($learner); // warms the caches of the auth and the entitlement

    $queries = QueryLog::during(fn () => gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden());

    expect(QueryLog::matching($queries, '/\bfrom\s+"?words"?/i'))->toBe([])
        ->and(QueryLog::matching($queries, '/\binsert\b/i'))->toHaveCount(1); // the event, nothing else
});

it('pays the run beyond the free one with a ticket in the transaction of the start: free_slot = ticket, one entry spend:run:{id}, tickets - 1', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameProfile::factory()->withTickets(1)->create(['learner_id' => $learner->id]);
    gameLimitsPlayed($learner, $course->slug, $lesson->id);

    $started = gameLimitsStart($learner, $course->slug, $lesson->id)->assertCreated();
    $run = GameRun::query()->findOrFail($started->json('id'));
    $entry = GameTicketLedgerEntry::query()->where('idempotency_key', "spend:run:{$run->id}")->sole();

    expect($run->free_slot)->toBe(FreeSlot::Ticket)
        ->and($entry->delta)->toBe(-1)
        ->and($entry->source)->toBe(TicketSource::Spend)
        ->and($entry->run_id)->toBe($run->id)
        ->and($entry->learner_id)->toBe($learner->id)
        ->and(GameProfile::query()->where('learner_id', $learner->id)->value('tickets'))->toBe(0)
        ->and(gameLimitsEvents('game.ticket_spent'))->toBe([['run_id' => $run->id]])
        ->and(gameLimitsEvents('game.run_started')[1]['free_slot'] ?? null)->toBe('ticket')
        ->and(gameLimitsEvents('game.limit_reached'))->toBe([]);

    $me = test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk();
    expect($me->json('game.tickets'))->toBe(0)
        ->and($me->json('game.limits.runs'))->toBe(gameLimitsBlock(1, 2, LIMITS_DAY_END))
        ->and($me->json('game.limits.tickets'))->toBe(['balance' => 0, 'max' => 10]);

    // The ticket is gone: the next start is refused, and says so.
    GameKit::play(app(RunService::class), $run, $learner);
    gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden()
        ->assertJsonPath('error.details', ['kind' => 'runs', 'limit' => 1, 'used' => 2, 'tickets' => 0, 'resets_at' => LIMITS_DAY_END]);
});

it('does not spend a ticket while the free run of the day is there, nor for Pro', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameProfile::factory()->withTickets(2)->create(['learner_id' => $learner->id]);

    $first = gameLimitsPlayed($learner, $course->slug, $lesson->id);
    expect($first->free_slot)->toBe(FreeSlot::Daily)
        ->and(GameProfile::query()->where('learner_id', $learner->id)->value('tickets'))->toBe(2)
        ->and(GameTicketLedgerEntry::query()->count())->toBe(0);

    PracticeKit::pro($learner);
    $second = gameLimitsPlayed($learner, $course->slug, $lesson->id);
    $third = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    expect($second->free_slot)->toBeNull()
        ->and($third->free_slot)->toBeNull()
        ->and(GameProfile::query()->where('learner_id', $learner->id)->value('tickets'))->toBe(2)
        ->and(GameTicketLedgerEntry::query()->count())->toBe(0)
        ->and(gameLimitsEvents('game.limit_reached'))->toBe([]);
});

it('takes no lock of the learner row for Pro, and for a Free learner whose limit is empty', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    $lockSql = '/^select "id" from "learners" where "learners"\."id" = \? and "learners"\."deleted_at" is null limit 1/'; // PostgreSQL adds `for no key update`, SQLite nothing

    // Free with the limit: the row is read FOR [NO KEY] UPDATE (SQLite ignores the clause, the statement is there).
    $free = QueryLog::during(fn () => $runs->start($learner, $course->slug, $lesson->id, replaceActive: true));
    expect(QueryLog::matching($free, $lockSql))->toHaveCount(1);

    GameKit::limits(null);
    $none = QueryLog::during(fn () => $runs->start($learner, $course->slug, $lesson->id, replaceActive: true));
    expect(QueryLog::matching($none, $lockSql))->toBe([]);

    GameKit::limits(1);
    PracticeKit::pro($learner);
    app(EntitlementResolver::class)->for($learner);
    $pro = QueryLog::during(fn () => $runs->start($learner, $course->slug, $lesson->id, replaceActive: true));
    expect(QueryLog::matching($pro, $lockSql))->toBe([]);
});

it('refuses the very first run when the limit is 0 (only Pro) unless there is a ticket', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameKit::limits(0);

    gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden()
        ->assertJsonPath('error.details', ['kind' => 'runs', 'limit' => 0, 'used' => 0, 'tickets' => 0, 'resets_at' => LIMITS_DAY_END]);

    GameProfile::factory()->withTickets(1)->create(['learner_id' => $learner->id]);
    $started = gameLimitsStart($learner, $course->slug, $lesson->id)->assertCreated();
    expect(GameRun::query()->findOrFail($started->json('id'))->free_slot)->toBe(FreeSlot::Ticket)
        ->and(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(0, 1, LIMITS_DAY_END));
});

// ---------------------------------------------------------------- what holds a place

it('gives the place back for a run abandoned or run out without a single answer, and keeps it taken after one answer', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    // Abandoned at once: not counted.
    $runs->abandon($runs->start($learner, $course->slug, $lesson->id), $learner);
    expect(gameLimitsMe($learner)['runs']['used'])->toBe(0);

    // Run out at once (the scheduler): not counted.
    $expired = $runs->start($learner, $course->slug, $lesson->id);
    $later = CarbonImmutable::parse('2026-11-05 10:31:00');
    CarbonImmutable::setTestNow($later);
    expect($runs->closeExpired($later))->toBe(1)
        ->and($expired->refresh()->finish_reason)->toBe(RunFinishReason::Expired)
        ->and(gameLimitsMe($learner)['runs']['used'])->toBe(0);

    // An active run holds a place while it is on.
    $active = $runs->start($learner, $course->slug, $lesson->id, now: $later);
    expect(gameLimitsMe($learner)['runs']['used'])->toBe(1);

    // One answer, then abandoned: the place is spent.
    $element = GameKit::current($active);
    $runs->answer($active, $learner, (string) Str::uuid(), $element['id'], GameKit::wrongAnswer($element), null, $later);
    $runs->abandon($active, $learner, $later);
    expect($active->refresh()->finish_reason)->toBe(RunFinishReason::Abandoned)
        ->and($active->answered)->toBe(1)
        ->and(gameLimitsMe($learner)['runs']['used'])->toBe(1);

    gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden()->assertJsonPath('error.details.used', 1);
});

it('does not count a live run past its time without an answer in /me.game.limits before anybody closes it, and shows its ticket as back (G2.13)', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);

    // The free run of the day, untouched, past its time - the scheduler has not come: the block says the day is free, as a start would find it.
    $stale = $runs->start($learner, $course->slug, $lesson->id);
    expect(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(1, 1, LIMITS_DAY_END));
    $later = CarbonImmutable::parse('2026-11-05 10:31:00');
    CarbonImmutable::setTestNow($later);
    expect($stale->refresh()->isActive())->toBeTrue()
        ->and(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(1, 0, LIMITS_DAY_END));
    gameLimitsStart($learner, $course->slug, $lesson->id)->assertCreated(); // and the start passes: it closes the stale run on its way
    expect($stale->refresh()->finish_reason)->toBe(RunFinishReason::Expired);

    // A run paid with a ticket, untouched, past its time: the place of the day is taken by the played run, the ticket is counted as back.
    GameKit::play($runs, $runs->activeFor($learner, $later) ?? throw new RuntimeException('No run.'), $learner, now: $later);
    app(TicketService::class)->grantForStreak($learner, 7, '2026-11-05');
    $paid = $runs->start($learner, $course->slug, $lesson->id, now: $later);
    expect($paid->free_slot)->toBe(FreeSlot::Ticket)
        ->and(gameLimitsMe($learner)['tickets'])->toBe(['balance' => 0, 'max' => 10]);
    CarbonImmutable::setTestNow($later->addMinutes(31));
    $me = test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/me')->assertOk();
    expect($paid->refresh()->isActive())->toBeTrue()
        ->and($me->json('game.limits.runs'))->toBe(gameLimitsBlock(1, 1, LIMITS_DAY_END))
        ->and($me->json('game.limits.tickets'))->toBe(['balance' => 1, 'max' => 10])
        ->and($me->json('game.tickets'))->toBe(1);

    // With an answer the stale run keeps its place and its ticket, whatever the clock says.
    $answered = $runs->start($learner, $course->slug, $lesson->id, now: $later->addMinutes(31));
    expect($answered->free_slot)->toBe(FreeSlot::Ticket)->and(GameProfile::query()->where('learner_id', $learner->id)->value('tickets'))->toBe(0);
    $element = GameKit::current($answered);
    $runs->answer($answered, $learner, (string) Str::uuid(), $element['id'], GameKit::wrongAnswer($element), null, $later->addMinutes(31));
    CarbonImmutable::setTestNow($later->addMinutes(62));
    expect($answered->refresh()->isActive())->toBeTrue()
        ->and(gameLimitsMe($learner))->toMatchArray(['runs' => gameLimitsBlock(1, 2, LIMITS_DAY_END), 'tickets' => ['balance' => 0, 'max' => 10]]);
});

it('returns the active run of the same stop to a second tab even at the limit, and refuses a replacement without a ticket after the first answer', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    $run = $runs->start($learner, $course->slug, $lesson->id);
    $element = GameKit::current($run);
    $runs->answer($run, $learner, (string) Str::uuid(), $element['id'], GameKit::rightAnswers($element)[0]);

    // The same stop again: the run that is on is the answer, the limit is not even read.
    gameLimitsStart($learner, $course->slug, $lesson->id)->assertCreated()->assertJsonPath('id', $run->id);
    expect(gameLimitsEvents('game.limit_reached'))->toBe([]);

    // "Once more" would replace it - but the run that is on holds the place of the day and there is no ticket: the courtesy check refuses
    // before anything is touched, and the run stays on (the replacement under the lock is the race of GameRunConcurrencyTest).
    gameLimitsStart($learner, $course->slug, $lesson->id, replace: true)->assertForbidden()->assertJsonPath('error.code', 'game_limit_reached');
    expect($run->refresh()->isActive())->toBeTrue()
        ->and(GameRun::query()->where('learner_id', $learner->id)->count())->toBe(1)
        ->and(gameLimitsEvents('game.limit_reached'))->toBe([['kind' => 'runs', 'limit' => 1]]);
});

it('counts the map runs of the current window only: yesterday\'s run is not today\'s', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    GameRun::factory()->forLesson($lesson)->create([
        'learner_id' => $learner->id, 'status' => 'finished', 'finish_reason' => 'completed', 'answered' => 8,
        'started_at' => CarbonImmutable::parse('2026-11-04 18:59:59'), 'local_date' => '2026-11-04', 'active_learner_id' => null,
    ]);
    GameRun::factory()->forLesson($lesson)->create([
        'learner_id' => $learner->id, 'status' => 'finished', 'finish_reason' => 'completed', 'answered' => 8,
        'started_at' => CarbonImmutable::parse('2026-11-04 19:00:00'), 'local_date' => '2026-11-05', 'active_learner_id' => null,
    ]);

    expect(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(1, 1, LIMITS_DAY_END));
});

it('does not limit the daily caravan nor the boss of a class: no lock, no claim', function (): void {
    [$learner] = GameKit::stop(8);
    $limits = app(GameLimits::class);
    $now = CarbonImmutable::now();

    expect($limits->lockForRun($learner, RunKind::Daily, $now))->toBeNull()
        ->and($limits->lockForRun($learner, RunKind::ClassBoss, $now))->toBeNull()
        ->and($limits->lockForRun($learner, RunKind::Stop, $now))->toBeInstanceOf(PendingRunClaim::class)
        ->and($limits->lockForRun($learner, RunKind::Boss, $now))->toBeInstanceOf(PendingRunClaim::class);

    GameKit::limits(0);
    $limits->assertRunAllowed($learner, RunKind::Daily, $now);
    $limits->assertRunAllowed($learner, RunKind::ClassBoss, $now);
    expect(fn () => $limits->assertRunAllowed($learner, RunKind::Stop, $now))->toThrow(GameException::class);
    expect(fn () => $limits->assertRunAllowed($learner, RunKind::Boss, $now))->toThrow(GameException::class);
});

it('keeps the limit of the day across a change of timezone: the day is carried over, nothing is reset', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    gameLimitsPlayed($learner, $course->slug, $lesson->id);

    // Kiritimati is past midnight already (UTC+14): a fresh day, were the zone alone to decide.
    test()->withHeaders(PracticeKit::headers($learner))->patchJson('/api/twa/me/settings', ['timezone' => 'Pacific/Kiritimati'])->assertOk();
    $learner->refresh();

    // The day is carried over (docs/12 §5.3): the place stays taken until the first midnight of the new zone after the old day's end.
    expect(gameLimitsMe($learner)['runs'])->toBe(gameLimitsBlock(1, 1, '2026-11-06T10:00:00.000Z'));
    gameLimitsStart($learner, $course->slug, $lesson->id)->assertForbidden()->assertJsonPath('error.details.resets_at', '2026-11-06T10:00:00.000Z');
});

// ---------------------------------------------------------------- bosses (the method of G2.4)

it('opens the bosses of the first boss_stages cities to Free and refuses the rest with 403 game_pro_required {kind, stage_number}', function (): void {
    [$learner] = GameKit::stop(8);
    $limits = app(GameLimits::class);

    $limits->assertBossAllowed($learner, 1);
    expect(fn () => $limits->assertBossAllowed($learner, 2))->toThrow(function (GameException $e): void {
        expect($e->errorCode)->toBe('game_pro_required')
            ->and($e->httpStatus())->toBe(403)
            ->and($e->details)->toBe(['kind' => 'boss', 'stage_number' => 2]);
    });
    expect(gameLimitsEvents('game.limit_reached'))->toBe([['kind' => 'boss', 'limit' => 1]]);

    GameKit::limits(1, 0);
    expect(fn () => $limits->assertBossAllowed($learner, 1))->toThrow(GameException::class);

    GameKit::limits(1, null);
    $limits->assertBossAllowed($learner, 24);

    GameKit::limits(1, 1);
    PracticeKit::pro($learner);
    $limits->assertBossAllowed($learner, 24);
    expect(gameLimitsEvents('game.limit_reached'))->toHaveCount(2);
});

// ---------------------------------------------------------------- boosters (the method of G2.5b)

it('allows boosters_per_week uses in the local week from Monday, then 409 booster_limit_reached {limit, used, resets_at}; last week does not count', function (): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $limits = app(GameLimits::class);
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);
    $item = GameItem::factory()->booster()->create();

    $limits->assertBoosterAllowed($learner);

    // A use last week (Monday the 26th of October) is not this week's.
    GameBoosterUse::factory()->create(['run_id' => $run->id, 'item_id' => $item->id, 'week_start' => '2026-10-26', 'used_at' => '2026-10-28 10:00:00']);
    $limits->assertBoosterAllowed($learner);
    expect(gameLimitsMe($learner)['boosters'])->toBe(gameLimitsBlock(1, 0, LIMITS_WEEK_END));

    GameBoosterUse::factory()->create(['run_id' => $run->id, 'item_id' => $item->id, 'week_start' => '2026-11-02', 'used_at' => '2026-11-03 10:00:00', 'element_id' => 'e1']);
    expect(gameLimitsMe($learner)['boosters'])->toBe(gameLimitsBlock(1, 1, LIMITS_WEEK_END));
    expect(fn () => $limits->assertBoosterAllowed($learner))->toThrow(function (GameException $e): void {
        expect($e->errorCode)->toBe('booster_limit_reached')
            ->and($e->httpStatus())->toBe(409)
            ->and($e->details)->toBe(['limit' => 1, 'used' => 1, 'resets_at' => LIMITS_WEEK_END, 'upsell' => true]);
    });
    expect(gameLimitsEvents('game.limit_reached'))->toBe([['kind' => 'boosters', 'limit' => 1]]);

    // Pro: the limit of the Pro plan.
    PracticeKit::pro($learner);
    $limits->assertBoosterAllowed($learner);
    expect(gameLimitsMe($learner)['boosters'])->toBe(gameLimitsBlock(5, 1, LIMITS_WEEK_END));

    // No limit at all.
    config(['game.pro_limits.boosters_per_week' => null]);
    expect(gameLimitsMe($learner)['boosters'])->toBeNull();
});
