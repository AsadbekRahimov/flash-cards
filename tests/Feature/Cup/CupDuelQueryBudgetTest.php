<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Operations\Support\PureCache;
use App\Domain\Practice\Enums\PracticeMode;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Models\Cup;
use App\Models\CupDuel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\QueryLog;

/*
 * The owner's requirement on SQL (docs/33 §11.9, docs/38): the statements of the duels are pinned here - the challenge, the acceptance,
 * the decline, the result, and `cups:sweep-duels` on N rows (a batch: the expiry does not grow with N; the scoring is a constant per
 * duel, one transaction each under its own lock). A change that adds a statement fails this file and has to say why. Product events
 * count (outside an HTTP request they are written at once). SQLite and PostgreSQL alike.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The statements of `$fn`, with the list in the message of a failure. @return list<string> */
function duelBudget(Closure $fn): array
{
    Cache::flush();
    PureCache::store()->flush();

    return QueryLog::during($fn);
}

function preparedCup(Cup $cup): Cup
{
    return Cup::query()->with('telegramGroup')->findOrFail($cup->id);
}

it('challenges in 8 statements privately and 9 publicly (the budget of the chat under the lock of the cup, the outbox row)', function (): void {
    ['cup' => $cup, 'learners' => [$a, , $c], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running();
    $service = app(CupDuelService::class);
    $loaded = preparedCup($cup);

    $private = duelBudget(fn () => $service->challenge($a, $loaded, $pb->id, false, PracticeMode::Pairs, (string) Str::uuid(), $now));
    $public = duelBudget(fn () => $service->challenge($c, $loaded, null, true, PracticeMode::Pairs, (string) Str::uuid(), $now));
    $repeat = duelBudget(function () use ($service, $a, $loaded, $now): void {
        $duel = CupDuel::query()->where('challenger_participant_id', CupDuel::query()->min('challenger_participant_id'))->firstOrFail();
        DB::flushQueryLog();
        $service->challenge($a, $loaded, null, false, PracticeMode::Pairs, $duel->request_id, $now);
    });

    // participant, request_id, target, friendship, limits + slots + my open challenge (one statement), the check of Telegram written,
    // INSERT … SELECT (the cup and both players read - and on PostgreSQL locked - in it, docs/33 §11.12), event
    expect($private)->toHaveCount(8, implode("\n", $private))
        // participant, request_id, limits, check written, FOR NO KEY UPDATE of the cup, the public budget again, INSERT … SELECT, the outbox row, event
        ->and($public)->toHaveCount(9, implode("\n", $public))
        ->and(QueryLog::matching($public, '/for no key update|for update/i'))->toHaveCount(DB::getDriverName() === 'pgsql' ? 1 : 0)
        ->and($repeat)->toHaveCount(2, implode("\n", $repeat));
    foreach ([$private, $public] as $statements) {
        $insert = QueryLog::matching($statements, '/^insert into cup_duels/i');
        expect($insert)->toHaveCount(1)
            ->and($insert[0])->toContain('p.withdrawn_at IS NULL')
            ->and(str_contains($insert[0], 'FOR KEY SHARE OF c FOR SHARE OF p'))->toBe(DB::getDriverName() === 'pgsql');
    }
});

it('accepts a rated duel in 21 statements, 3 of them the material of the snapshot and 7 the transaction', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, now: $now)->duel;
    $service = app(CupDuelService::class);

    $accept = duelBudget(fn () => $service->accept($b, $duel->id, true, $now->addMinute()));
    $again = duelBudget(fn () => $service->accept($b, $duel->id, true, $now->addMinutes(2)));

    // duel, cup, group, me, challenger, friendship, check written, slots of the day, the challenger's learner, course, material (3),
    // FOR UPDATE of the duel, INSERT of both slots, INSERT battle + 2 participants, UPDATE duel, events battle.accepted + cup.duel_accepted
    expect($accept)->toHaveCount(21, implode("\n", $accept))
        ->and(QueryLog::matching($accept, '/insert into ["`]?cup_duel_slots/i'))->toHaveCount(1)
        ->and($again)->toHaveCount(4, implode("\n", $again)); // duel, cup, group, me
});

it('accepts a public challenge with one statement more: the row of the edit of its message (docs/33 §11.11 п. 7)', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true, now: $now)->duel;

    $accept = duelBudget(fn () => app(CupDuelService::class)->accept($b, $duel->id, true, $now->addMinute()));

    expect($accept)->toHaveCount(22, implode("\n", $accept))
        ->and(QueryLog::matching($accept, '/insert into ["`]?cup_messages/i'))->toHaveCount(1);
});

it('scores a rated duel in 12 statements, a friendly one in 5, a void one in 5', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb, $pc], 'start' => $now] = CupDuelKit::running();
    $rated = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($rated, $a, $b, 3, 2);
    $friendly = CupDuelKit::accept($c, CupDuelKit::challenge($a, $cup, $pc)->duel, false);
    CupDuelKit::playOut($friendly, $a, $c, 1, 1);
    $service = app(CupDuelService::class);

    $scoreRated = duelBudget(fn () => $service->score($rated->id));
    $scoreFriendly = duelBudget(fn () => $service->score($friendly->id));
    $again = duelBudget(fn () => $service->score($rated->id));

    $void = CupDuelKit::accept($c, CupDuelKit::challenge($b, $cup, $pc)->duel, false);
    DB::table('battles')->where('id', $void->battle_id)->update(['status' => 'closed', 'end_reason' => 'room_expired', 'open_pair' => null, 'phase' => null, 'finished_at' => now()]);
    DB::table('battle_participants')->where('battle_id', $void->battle_id)->update(['active_learner_id' => null]);
    $scoreVoid = duelBudget(fn () => $service->score($void->id));

    // FOR UPDATE duel, the battle and its answers (one), FOR UPDATE of both participants, the cup (status, sizes), 2 × (upsert day,
    // read days, UPDATE best_*), UPDATE duel, event
    expect($scoreRated)->toHaveCount(12, implode("\n", $scoreRated))
        ->and($scoreFriendly)->toHaveCount(5, implode("\n", $scoreFriendly))
        ->and($again)->toHaveCount(1, implode("\n", $again))
        // FOR UPDATE duel, the battle, DELETE slots, UPDATE duel, event
        ->and($scoreVoid)->toHaveCount(5, implode("\n", $scoreVoid));
});

it('declines a public challenge in 7 statements: its edit is one outbox row', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb]] = CupDuelKit::running();
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true)->duel;

    $decline = duelBudget(fn () => app(CupDuelService::class)->decline($b, $duel->id));

    expect($decline)->toHaveCount(7, implode("\n", $decline)); // duel, cup, group, me, FOR UPDATE, UPDATE, the outbox row
});

it('sweeps N expired challenges in the same 3 statements for any N, and scores each finished duel in a constant', function (int $n): void {
    config(['cup.duel.public_per_chat' => 50, 'cup.duel.public_per_participant' => 50, 'cup.duel.daily_challenges' => 50]);
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupDuelKit::running(9);
    foreach (array_slice($learners, 0, $n) as $learner) {
        CupDuelKit::challenge($learner, $cup, public: true, now: $now);
    }
    $service = app(CupDuelService::class);

    $expire = duelBudget(fn () => $service->sweep($now->addMinutes(11)));
    $idle = duelBudget(fn () => $service->sweep($now->addMinutes(12)));

    expect($expire)->toHaveCount(3, implode("\n", $expire)) // the batch UPDATE … RETURNING, the edits (one INSERT), the accepted ones
        ->and(CupDuel::query()->where('status', 'expired')->count())->toBe($n)
        ->and(DB::table('cup_messages')->where('dedupe_key', 'like', 'duel:%:end')->count())->toBe($n)
        ->and($idle)->toHaveCount(2, implode("\n", $idle));
})->with([1, 8]);

it('scores K finished duels in the sweep in 2 + 12·K statements', function (int $k): void {
    ['cup' => $cup, 'learners' => $learners, 'participants' => $participants] = CupDuelKit::running(2 * $k);
    for ($i = 0; $i < $k; $i++) {
        $duel = CupDuelKit::accept($learners[2 * $i + 1], CupDuelKit::challenge($learners[2 * $i], $cup, $participants[2 * $i + 1])->duel);
        CupDuelKit::playOut($duel, $learners[2 * $i], $learners[2 * $i + 1], 1, 1);
    }

    $sweep = duelBudget(fn () => app(CupDuelService::class)->sweep(CarbonImmutable::now()->addMinute()));

    expect($sweep)->toHaveCount(2 + 12 * $k, implode("\n", $sweep))
        ->and(CupDuel::query()->where('status', 'done')->count())->toBe($k);
})->with([1, 3]);
