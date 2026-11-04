<?php

declare(strict_types=1);

use App\Domain\Engagement\Services\ReferralService;
use App\Domain\Game\Enums\FreeSlot;
use App\Domain\Game\Enums\TicketSource;
use App\Domain\Game\Services\Economy\TicketService;
use App\Domain\Game\Services\Runs\RunService;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learners\Services\StreakService;
use App\Models\AuditLog;
use App\Models\GameProfile;
use App\Models\GameRun;
use App\Models\GameTicketLedgerEntry;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Game\GameKit;
use Tests\Support\Practice\PracticeKit;
use Tests\Support\QueryLog;

/*
 * docs/18 §12.7.3 (G2.8): the tickets of the Free runs. +1 every 7 days of the streak (once per day), +3 to the inviter for a rewarded
 * referral (once per referral, through ReferralService::reward; a flagged one gives nothing), the cap of the balance and the event
 * `game.ticket_capped`, the grant of an administrator with its audit entry, the spend at the start of a run; and the invariant
 * `SUM(game_ticket_ledger.delta) = game_profiles.tickets` after every move. The race for the last ticket is GameRunConcurrencyTest's.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function gameTickets(): TicketService
{
    return app(TicketService::class);
}

/** The invariant of docs/18 §12.7.3 for one learner: the ledger sums to the balance. */
function expectGameTicketInvariant(Learner $learner): void
{
    $balance = (int) GameProfile::query()->where('learner_id', $learner->id)->value('tickets');
    $sum = (int) GameTicketLedgerEntry::query()->where('learner_id', $learner->id)->sum('delta');
    expect($sum)->toBe($balance, "SUM(ledger) {$sum} <> tickets {$balance}");
}

/** @return list<array<string, mixed>> */
function gameTicketEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/** @return list<array{delta: int, source: string, key: string}> */
function gameTicketLedger(Learner $learner): array
{
    return GameTicketLedgerEntry::query()->where('learner_id', $learner->id)->orderBy('id')->get()
        ->map(fn (GameTicketLedgerEntry $e): array => ['delta' => $e->delta, 'source' => $e->source->value, 'key' => $e->idempotency_key])->all();
}

/** An activated referral of a fresh learner, rewarded by the service (both sides), the way the job does it. */
function gameTicketReferral(Learner $inviter, ?Learner $referee = null, bool $flagged = false): Referral
{
    $referral = Referral::factory()->activated()->create([
        'referrer_learner_id' => $inviter->id,
        'referee_learner_id' => ($referee ?? Learner::factory()->create())->id,
        'flagged_at' => $flagged ? CarbonImmutable::now() : null,
    ]);
    app(ReferralService::class)->reward($referral, CarbonImmutable::now());

    return $referral->refresh();
}

// ---------------------------------------------------------------- the streak

it('gives one ticket on every 7th day of the streak, once per day, and nothing on the other days', function (): void {
    $learner = Learner::factory()->create();

    // Not a 7th day: nothing, and not even a query (the hook runs on every held day).
    expect(QueryLog::during(fn () => expect(gameTickets()->grantForStreak($learner, 6, '2026-11-04'))->toBeNull()))->toBe([])
        ->and(GameProfile::query()->where('learner_id', $learner->id)->exists())->toBeFalse()
        ->and(GameTicketLedgerEntry::query()->count())->toBe(0);

    $seventh = gameTickets()->grantForStreak($learner, 7, '2026-11-05');
    $again = gameTickets()->grantForStreak($learner, 7, '2026-11-05');
    expect($seventh?->applied)->toBeTrue()
        ->and($seventh?->delta)->toBe(1)
        ->and($seventh?->balance)->toBe(1)
        ->and($again?->applied)->toBeFalse()
        ->and($again?->balance)->toBe(1)
        ->and(gameTicketLedger($learner))->toBe([['delta' => 1, 'source' => 'streak', 'key' => "streak:{$learner->id}:2026-11-05"]])
        ->and(GameProfile::query()->where('learner_id', $learner->id)->sole()->tickets_earned_total)->toBe(1)
        ->and(gameTicketEvents('game.ticket_granted'))->toEqual([['source' => 'streak', 'delta' => 1, 'balance' => 1]]);

    gameTickets()->grantForStreak($learner, 14, '2026-11-12');
    gameTickets()->grantForStreak($learner, 0, '2026-11-13');
    gameTickets()->grantForStreak($learner, 15, '2026-11-13');
    expect(gameTickets()->balance($learner->id))->toBe(2);
    expectGameTicketInvariant($learner);

    // Pro collects tickets too (they wait for the end of the subscription); a config of 0 gives nothing.
    PracticeKit::pro($learner);
    expect(gameTickets()->grantForStreak($learner, 21, '2026-11-19')?->balance)->toBe(3);
    config(['game.tickets.per_streak_week' => 0]);
    expect(gameTickets()->grantForStreak($learner, 28, '2026-11-26'))->toBeNull()
        ->and(gameTickets()->balance($learner->id))->toBe(3);
});

it('gives the ticket of the 7th day from the streak itself - held by the goal or by a run - once, and nothing on a second reading of the day', function (bool $byRun): void {
    [$learner, $course, $lesson] = GameKit::stop(8);
    $learner->forceFill(['streak_current' => 6, 'streak_best' => 6, 'streak_last_date' => '2026-11-04', 'daily_goal' => 1])->save();
    $runs = app(RunService::class);

    if ($byRun) {
        GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    } else {
        DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, CarbonImmutable::now()));
    }

    expect(Learner::query()->findOrFail($learner->id)->streak_current)->toBe(7)
        ->and(gameTickets()->balance($learner->id))->toBe(1)
        ->and(gameTicketLedger($learner))->toBe([['delta' => 1, 'source' => 'streak', 'key' => "streak:{$learner->id}:2026-11-05"]])
        ->and(gameTicketEvents('game.ticket_granted'))->toEqual([['source' => 'streak', 'delta' => 1, 'balance' => 1]]);

    // The day read again (a second run, another grade, the Today screen): the series is the same, no second ticket.
    CarbonImmutable::setTestNow('2026-11-05 10:30:00');
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    DB::transaction(fn () => app(DailyStatsService::class)->recordReview($learner, 4, 500, false, CarbonImmutable::now()));
    app(StreakService::class)->apply($learner, '2026-11-05');
    test()->withHeaders(PracticeKit::headers($learner))->getJson('/api/twa/today')->assertOk();

    expect(gameTickets()->balance($learner->id))->toBe(1)
        ->and(GameTicketLedgerEntry::query()->count())->toBe(1);
    expectGameTicketInvariant($learner);
})->with(['held by a goal' => [false], 'held by a run' => [true]]);

// ---------------------------------------------------------------- the referrals

it('gives the inviter 3 tickets for a rewarded referral, once - a repeat of the reward and the job grants nothing more; the invited learner gets none', function (): void {
    $inviter = Learner::factory()->create();
    $referee = Learner::factory()->create();
    $referral = gameTicketReferral($inviter, $referee);

    expect($referral->status)->toBe('rewarded')
        ->and(gameTickets()->balance($inviter->id))->toBe(3)
        ->and(gameTickets()->balance($referee->id))->toBe(0)
        ->and(gameTicketLedger($inviter))->toBe([['delta' => 3, 'source' => 'referral', 'key' => "referral:{$referral->id}"]])
        ->and(gameTicketEvents('game.ticket_granted'))->toEqual([['source' => 'referral', 'delta' => 3, 'balance' => 3]]);

    app(ReferralService::class)->reward($referral, CarbonImmutable::now()->addMinute());
    expect(gameTickets()->grantForReferral($referral->refresh())?->applied)->toBeFalse()
        ->and(gameTickets()->balance($inviter->id))->toBe(3)
        ->and(GameTicketLedgerEntry::query()->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $inviter->id)->count())->toBe(1);
    expectGameTicketInvariant($inviter);
});

it('gives no tickets for a flagged referral, one that is not rewarded, or to a blocked inviter', function (): void {
    $inviter = Learner::factory()->create();

    $flagged = gameTicketReferral($inviter, flagged: true);
    expect($flagged->status)->toBe('activated')
        ->and(gameTickets()->grantForReferral($flagged))->toBeNull()
        ->and(gameTickets()->balance($inviter->id))->toBe(0);

    $pending = Referral::factory()->create(['referrer_learner_id' => $inviter->id]);
    expect(gameTickets()->grantForReferral($pending))->toBeNull();

    $rewarded = Referral::factory()->rewarded()->create(['referrer_learner_id' => $inviter->id]);
    $inviter->forceFill(['blocked_at' => CarbonImmutable::now()])->save();
    expect(gameTickets()->grantForReferral($rewarded))->toBeNull()
        ->and(GameTicketLedgerEntry::query()->count())->toBe(0);

    $inviter->forceFill(['blocked_at' => null])->save();
    expect(gameTickets()->grantForReferral($rewarded)?->balance)->toBe(3);
});

// ---------------------------------------------------------------- the cap

it('caps the balance at tickets.max: a grant is cut to the room left, what is cut is not written, and game.ticket_capped says how much', function (): void {
    $learner = Learner::factory()->create();
    gameTickets()->grantByAdmin($learner, 9, User::factory()->create());

    $referral = gameTicketReferral($learner);
    expect(gameTickets()->balance($learner->id))->toBe(10)
        ->and(gameTicketLedger($learner)[1])->toBe(['delta' => 1, 'source' => 'referral', 'key' => "referral:{$referral->id}"])
        ->and(gameTicketEvents('game.ticket_granted')[1])->toEqual(['source' => 'referral', 'delta' => 1, 'balance' => 10])
        ->and(gameTicketEvents('game.ticket_capped'))->toEqual([['source' => 'referral', 'lost' => 2]]);

    // At the cap: nothing is written, the key is NOT burnt (the next 7th day is another key anyway), only the capped event.
    $atCap = gameTickets()->grantForStreak($learner, 7, '2026-11-05');
    expect($atCap?->applied)->toBeFalse()
        ->and($atCap?->lost)->toBe(1)
        ->and($atCap?->balance)->toBe(10)
        ->and(GameTicketLedgerEntry::query()->count())->toBe(2)
        ->and(gameTicketEvents('game.ticket_capped'))->toHaveCount(2)
        ->and(GameProfile::query()->where('learner_id', $learner->id)->sole()->tickets_earned_total)->toBe(10);
    expectGameTicketInvariant($learner);

    config(['game.tickets.max' => 12]);
    expect(gameTickets()->grantForStreak($learner, 7, '2026-11-05')?->balance)->toBe(11);
});

// ---------------------------------------------------------------- the administrator

it('lets an administrator grant and take tickets by hand: an audit entry first, the ledger under its key, the event with the user', function (): void {
    $learner = Learner::factory()->create();
    $admin = User::factory()->create();

    $grant = gameTickets()->grantByAdmin($learner, 4, $admin, '10.0.0.1', 'a lost run');
    $audit = AuditLog::query()->where('action', 'game.tickets_adjusted')->sole();
    expect($grant->applied)->toBeTrue()
        ->and($grant->balance)->toBe(4)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->entity_type)->toBe(Learner::class)
        ->and($audit->entity_id)->toBe($learner->id)
        ->and($audit->ip)->toBe('10.0.0.1')
        ->and($audit->meta)->toBe(['delta' => 4, 'reason' => 'a lost run'])
        ->and(gameTicketLedger($learner))->toBe([['delta' => 4, 'source' => 'admin', 'key' => "admin:{$audit->id}"]])
        ->and(gameTicketEvents('game.tickets_adjusted'))->toEqual([['amount' => 4, 'audit_log_id' => $audit->id]])
        ->and(ProductEvent::query()->where('name', 'game.tickets_adjusted')->sole()->user_id)->toBe($admin->id)
        ->and(gameTicketEvents('game.ticket_granted'))->toEqual([['source' => 'admin', 'delta' => 4, 'balance' => 4]]);

    // Taking more than there is takes what there is; the ledger carries the real move.
    $taken = gameTickets()->grantByAdmin($learner, -6, $admin);
    expect($taken->balance)->toBe(0)
        ->and($taken->delta)->toBe(-4)
        ->and(AuditLog::query()->where('action', 'game.tickets_adjusted')->count())->toBe(2)
        ->and(gameTicketEvents('game.tickets_adjusted')[1])->toEqual(['amount' => -4, 'audit_log_id' => AuditLog::query()->max('id')]);
    expectGameTicketInvariant($learner);

    // A grant over the cap is cut like any other; nothing to move at all is no event but still an audit entry.
    expect(gameTickets()->grantByAdmin($learner, 30, $admin)->lost)->toBe(20)
        ->and(gameTickets()->grantByAdmin($learner, 1, $admin)->applied)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'game.tickets_adjusted')->count())->toBe(4)
        ->and(gameTicketEvents('game.tickets_adjusted'))->toHaveCount(3)
        ->and(fn () => gameTickets()->grantByAdmin($learner, 0, $admin))->toThrow(LogicException::class);
    expectGameTicketInvariant($learner);
});

// ---------------------------------------------------------------- the spend

it('spends a ticket at the start of a run beyond the free one, under the learner lock, in the transaction of the start - and keeps the invariant', function (): void {
    GameKit::limits(1);
    [$learner, $course, $lesson] = GameKit::stop(8);
    $runs = app(RunService::class);
    gameTickets()->grantForStreak($learner, 7, '2026-11-05');
    gameTickets()->grantForStreak($learner, 14, '2026-11-12');

    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner);
    $paid = $runs->start($learner, $course->slug, $lesson->id);

    expect($paid->free_slot)->toBe(FreeSlot::Ticket)
        ->and(gameTickets()->balance($learner->id))->toBe(1)
        ->and(gameTicketLedger($learner)[2])->toBe(['delta' => -1, 'source' => 'spend', 'key' => "spend:run:{$paid->id}"])
        ->and(GameTicketLedgerEntry::query()->where('idempotency_key', "spend:run:{$paid->id}")->sole()->run_id)->toBe($paid->id)
        ->and(gameTicketEvents('game.ticket_spent'))->toEqual([['run_id' => $paid->id]]);
    expectGameTicketInvariant($learner);

    // Without a ticket to take: a bug of the caller (the claim read one under the same lock), never a silent miss.
    $poor = Learner::factory()->create();
    expect(fn () => DB::transaction(fn () => gameTickets()->spendForRun($poor, $paid)))->toThrow(LogicException::class)
        ->and(GameTicketLedgerEntry::query()->where('source', TicketSource::Spend->value)->count())->toBe(1);
});

it('refuses a key that belongs to another learner instead of answering "already done"', function (): void {
    $one = Learner::factory()->create();
    $other = Learner::factory()->create();
    GameTicketLedgerEntry::factory()->create(['learner_id' => $one->id, 'idempotency_key' => 'referral:1']);
    $referral = Referral::factory()->rewarded()->create(['id' => 1, 'referrer_learner_id' => $other->id]);

    expect(fn () => gameTickets()->grantForReferral($referral))->toThrow(LogicException::class);
});

it('keeps SUM(ledger) = tickets through a mix of grants, cuts, takes and spends', function (): void {
    GameKit::limits(0);
    [$learner, $course, $lesson] = GameKit::stop(8);
    $admin = User::factory()->create();
    $runs = app(RunService::class);

    gameTickets()->grantByAdmin($learner, 8, $admin);
    gameTickets()->grantForReferral(Referral::factory()->rewarded()->create(['referrer_learner_id' => $learner->id])); // +2, 1 lost to the cap (the service would make the inviter Pro)
    $runs->abandon($runs->start($learner, $course->slug, $lesson->id), $learner); // -1 (limit 0: every run is a ticket)
    gameTickets()->grantByAdmin($learner, -3, $admin);     // -3
    gameTickets()->grantForStreak($learner, 7, '2026-11-05'); // +1
    GameKit::play($runs, $runs->start($learner, $course->slug, $lesson->id), $learner); // -1

    expect(gameTickets()->balance($learner->id))->toBe(6)
        ->and(GameRun::query()->where('learner_id', $learner->id)->where('free_slot', FreeSlot::Ticket->value)->count())->toBe(2);
    expectGameTicketInvariant($learner);
});
