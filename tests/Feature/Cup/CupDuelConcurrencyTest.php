<?php

declare(strict_types=1);

use App\Domain\Cup\Enums\CupDuelEndReason;
use App\Domain\Cup\Enums\CupDuelKind;
use App\Domain\Cup\Enums\CupDuelStatus;
use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Cup\Services\CupDuelService;
use App\Domain\Cup\Services\CupLifecycleService;
use App\Domain\Cup\Services\CupParticipationService;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Jobs\PostCupMessageJob;
use App\Jobs\ScoreCupDuelJob;
use App\Models\Battle;
use App\Models\Cup;
use App\Models\CupDuel;
use App\Models\CupParticipant;
use App\Models\Learner;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Cup\CupDuelKit;
use Tests\Support\Game\GameRace;

/*
 * docs/33 §5.7, §11.9 (D1): the real races of the duels - forked processes, each with its own PostgreSQL connection, released at the
 * same instant (GameRace). Committed data (DatabaseTruncation); a refusal is a result (`refused`), anything else thrown is a crash; a
 * deadlock or an endless wait is a crash too (lock_timeout 8 s). After every race the invariants are checked on the rows:
 * an `accepted` / `done` duel has exactly one battle and (rated) exactly two slots; a pair has one match; a day score is written once.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    if (config('cache.default') !== 'redis') {
        $this->markTestSkipped('Needs a cache shared by the processes (Redis) for the locks of the membership check.');
    }
    Cache::flush();
    Queue::fake([PostCupMessageJob::class, ScoreCupDuelJob::class]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/** A task of the race: a refusal of the cup or of the battle is a value, not a crash. */
function duelRaceTask(Closure $task): Closure
{
    return static function () use ($task): mixed {
        try {
            return $task();
        } catch (CupException|BattleException|PracticeException $e) {
            return ['refused' => $e->errorCode];
        }
    };
}

/** @param list<array<string, mixed>> $results @return list<mixed> */
function duelRaceValues(array $results): array
{
    expect(GameRace::crashes($results))->toBe([]);

    return array_map(static fn (array $r): mixed => $r['value'] ?? null, $results);
}

/** The rows of the duels agree with the battles and the slots. */
function assertDuelInvariants(): void
{
    foreach (CupDuel::query()->get() as $duel) {
        $slots = DB::table('cup_duel_slots')->where('cup_duel_id', $duel->id)->count();
        if (in_array($duel->status, [CupDuelStatus::Accepted, CupDuelStatus::Done], true)) {
            expect($duel->battle_id)->not->toBeNull("duel {$duel->id} has no battle")
                ->and($slots)->toBe($duel->rated ? 2 : 0, "duel {$duel->id}: slots");
        } else {
            expect($slots)->toBe(0, "duel {$duel->id} ({$duel->status->value}) holds slots");
        }
        if ($duel->status === CupDuelStatus::Open) {
            expect($duel->battle_id)->toBeNull();
        }
    }
    expect(Battle::query()->whereNotNull('open_pair')->count())
        ->toBe(Battle::query()->whereNotNull('open_pair')->distinct()->count('open_pair'), 'one match per pair');
}

function raceCup(int $cupId): Cup
{
    return Cup::query()->with('telegramGroup')->findOrFail($cupId);
}

it('makes one match when three participants accept the same open challenge at the same instant', function (): void {
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupDuelKit::running(4);
    $duel = CupDuelKit::challenge($learners[0], $cup, now: $now)->duel;
    $at = $now->addMinute();

    $tasks = [];
    foreach (array_slice($learners, 1) as $learner) {
        $id = $learner->id;
        $tasks[] = duelRaceTask(fn (): int => (int) app(CupDuelService::class)->accept(Learner::query()->findOrFail($id), $duel->id, true, $at)->duel->battle_id);
    }
    $values = duelRaceValues(GameRace::run($tasks));

    expect(array_values(array_filter($values, 'is_int')))->toHaveCount(1)
        ->and(Battle::query()->count())->toBe(1)
        ->and(DB::table('cup_duel_slots')->count())->toBe(2)
        ->and(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Accepted);
    foreach (array_filter($values, 'is_array') as $refused) {
        expect($refused['refused'])->toBeIn(['cup_duel_closed', 'cup_duel_not_found']);
    }
    assertDuelInvariants();
});

it('makes one match of two crossing acceptances (each accepts the other\'s challenge at the same instant)', function (bool $rated): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc], 'start' => $now] = CupDuelKit::running(3);
    $aToB = CupDuelKit::challenge($a, $cup, $pb, now: $now)->duel;
    $bToA = CupDuelKit::challenge($b, $cup, $pa, now: $now)->duel;
    if (! $rated) {
        // Both have played the rated duel of the day (a finished duel with $c holds their slots): two friendly acceptances.
        $old = CupDuel::factory()->create(['cup_id' => $cup->id, 'challenger_participant_id' => $pc->id]);
        foreach ([$pa, $pb] as $p) {
            DB::table('cup_duel_slots')->insert(['cup_id' => $cup->id, 'participant_id' => $p->id, 'day' => 1, 'cup_duel_id' => $old->id]);
        }
    }
    $at = $now->addMinute();

    $values = duelRaceValues(GameRace::run([
        duelRaceTask(fn (): int => (int) app(CupDuelService::class)->accept(Learner::query()->findOrFail($a->id), $bToA->id, $rated, $at)->duel->battle_id),
        duelRaceTask(fn (): int => (int) app(CupDuelService::class)->accept(Learner::query()->findOrFail($b->id), $aToB->id, $rated, $at)->duel->battle_id),
    ]));

    expect(array_values(array_filter($values, 'is_int')))->toHaveCount(1)
        ->and(Battle::query()->count())->toBe(1)
        ->and(CupDuel::query()->where('status', 'accepted')->count())->toBe(1);
    foreach (array_filter($values, 'is_array') as $refused) {
        expect($refused['refused'])->toBeIn($rated ? ['cup_duel_rating_changed', 'battle_busy'] : ['battle_busy']);
    }
    if (! $rated) {
        DB::table('cup_duel_slots')->where('cup_duel_id', CupDuel::query()->where('challenger_participant_id', $pc->id)->value('id'))->delete();
    }
    assertDuelInvariants();
})->with(['rated' => [true], 'friendly' => [false]]);

it('never makes a friendly duel by choice rated nor a second rated duel: it and a rated one accepted by the same participant at the same instant', function (bool $openToAll): void {
    // docs/33 §11.12: $a challenges $b friendly by choice, $c challenges $b (or anybody) rated; $b accepts both at the same instant.
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(3);
    $friendly = CupDuelKit::challenge($a, $cup, $pb, now: $now, kind: CupDuelKind::Friendly)->duel;
    $rated = CupDuelKit::challenge($c, $cup, $openToAll ? null : $pb, now: $now, kind: CupDuelKind::Rated)->duel;
    $at = $now->addMinute();

    $values = duelRaceValues(GameRace::run([
        duelRaceTask(fn (): string => 'friendly:'.app(CupDuelService::class)->accept(Learner::query()->findOrFail($b->id), $friendly->id, false, $at)->duel->id),
        duelRaceTask(fn (): string => 'rated:'.app(CupDuelService::class)->accept(Learner::query()->findOrFail($b->id), $rated->id, true, $at)->duel->id),
    ]));

    // One match for $b (a player is in one match at a time); the other acceptance is refused by the battle, never by a slot.
    expect(array_values(array_filter($values, 'is_string')))->toHaveCount(1)
        ->and(Battle::query()->count())->toBe(1);
    foreach (array_filter($values, 'is_array') as $refused) {
        expect($refused['refused'])->toBe('battle_busy');
    }
    $f = CupDuelKit::fresh($friendly);
    $r = CupDuelKit::fresh($rated);
    expect($f->rated)->toBeFalse()
        ->and(DB::table('cup_duel_slots')->where('cup_duel_id', $f->id)->count())->toBe(0)
        ->and(DB::table('cup_duel_slots')->count())->toBe($r->status === CupDuelStatus::Accepted ? 2 : 0)
        ->and(DB::table('cup_duel_slots')->where('participant_id', $pb->id)->count())->toBeLessThanOrEqual(1);
    assertDuelInvariants();
})->with(['addressed' => [false], 'open to all' => [true]]);

it('decides an acceptance and a withdrawal at the same instant one way only', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    $duel = CupDuelKit::challenge($a, $cup, $pb, public: true, now: $now)->duel;
    $at = $now->addMinute();

    $values = duelRaceValues(GameRace::run([
        duelRaceTask(fn (): string => app(CupDuelService::class)->accept(Learner::query()->findOrFail($b->id), $duel->id, true, $at)->duel->status->value),
        duelRaceTask(fn (): string => app(CupDuelService::class)->cancel(Learner::query()->findOrFail($a->id), $duel->id, $at)->status->value),
    ]));

    $final = CupDuelKit::fresh($duel);
    $ok = array_values(array_filter($values, 'is_string'));
    expect($ok)->toHaveCount(1)
        ->and($ok[0])->toBe($final->status->value)
        ->and(Battle::query()->count())->toBe($final->status === CupDuelStatus::Accepted ? 1 : 0)
        ->and(DB::table('cup_messages')->where('dedupe_key', 'duel:'.$duel->id.':end')->count())->toBe($final->status === CupDuelStatus::Canceled ? 1 : 0);
    assertDuelInvariants();
});

it('decides an acceptance and the expiry by the sweeper at the same instant one way only', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [, $pb], 'start' => $now] = CupDuelKit::running(2);
    $duel = CupDuelKit::challenge($a, $cup, $pb, now: $now)->duel;
    $edge = CarbonImmutable::instance($duel->expires_at);

    $values = duelRaceValues(GameRace::run([
        duelRaceTask(fn (): string => app(CupDuelService::class)->accept(Learner::query()->findOrFail($b->id), $duel->id, true, $edge->subSecond())->duel->status->value),
        duelRaceTask(fn (): int => app(CupDuelService::class)->sweep($edge->addSecond())['expired']),
        duelRaceTask(fn (): int => app(CupDuelService::class)->sweep($edge->addSecond())['expired']),
    ]));

    $final = CupDuelKit::fresh($duel);
    expect($final->status)->toBeIn([CupDuelStatus::Accepted, CupDuelStatus::Expired])
        ->and(array_sum(array_filter([$values[1], $values[2]], 'is_int')))->toBe($final->status === CupDuelStatus::Expired ? 1 : 0)
        ->and(Battle::query()->count())->toBe($final->status === CupDuelStatus::Accepted ? 1 : 0);
    assertDuelInvariants();
});

it('scores a duel once when the hook of the battle fires three times at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($duel, $a, $b, 4, 1);

    $values = duelRaceValues(GameRace::run(array_fill(0, 3, duelRaceTask(fn (): ?string => app(CupDuelService::class)->score($duel->id)?->value))));

    expect(array_values(array_filter($values)))->toBe(['done'])
        ->and(DB::table('cup_day_scores')->where('participant_id', $pa->id)->value('duel_points'))->toBe(4)
        ->and(CupParticipant::query()->findOrFail($pa->id)->best_total)->toBe(4)
        ->and(DB::table('product_events')->where('name', 'cup.duel_scored')->count())->toBe(1);
    assertDuelInvariants();
});

it('never changes the result of the finish: the scoring and the finish of the cup at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b, $c], 'participants' => [$pa, $pb, $pc]] = CupDuelKit::running(3);
    // $c leads by a round; the duel can turn the places around ($a 5 > $c 3) - if it lands before the places.
    DB::table('cup_day_scores')->insert(['cup_id' => $cup->id, 'participant_id' => $pc->id, 'day' => 1, 'round_points' => 3, 'duel_points' => 0, 'updated_at' => now()]);
    DB::table('cup_participants')->where('id', $pc->id)->update(['best_total' => 3, 'best_round_points' => 3]);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($duel, $a, $b, 5, 0);
    $finishAt = CarbonImmutable::instance(raceCup($cup->id)->ends_at)->addMinutes(31);

    duelRaceValues(GameRace::run([
        duelRaceTask(fn (): ?string => app(CupDuelService::class)->score($duel->id, $finishAt)?->value),
        duelRaceTask(fn (): bool => app(CupLifecycleService::class)->finish($cup->id, $finishAt)),
    ]));

    $final = CupDuelKit::fresh($duel);
    $pa = CupParticipant::query()->findOrFail($pa->id);
    $pc = CupParticipant::query()->findOrFail($pc->id);
    expect(raceCup($cup->id)->status->value)->toBe('finished')
        ->and($final->status)->toBe(CupDuelStatus::Done);
    if ($final->end_reason === CupDuelEndReason::CupClosed) {
        // The finish came first: the duel is not in the cup, and the places are the finish's.
        expect($pa->best_total)->toBe(0)->and($pc->final_rank)->toBe(1)->and($pa->final_rank)->toBe(2);
    } else {
        // The scoring came first: the finish counted it.
        expect($pa->best_total)->toBe(5)->and($pa->final_rank)->toBe(1)->and($pc->final_rank)->toBe(2);
    }
    $ranks = DB::select('SELECT id, final_rank, RANK() OVER (ORDER BY best_total DESC, best_round_points DESC) AS place FROM cup_participants WHERE cup_id = ?', [$cup->id]);
    foreach ($ranks as $row) {
        expect((int) $row->final_rank)->toBe((int) $row->place, 'the places agree with the scores');
    }
});

/*
 * The deadlock of the review of release 6 (docs/33 §11.9.2 п. 1): the scoring held both participants and inserted the first row of
 * their day (`cup_day_scores` - a day with only a duel), whose foreign key takes `FOR KEY SHARE` of the cup, while the finish held
 * `FOR UPDATE` of the cup and waited for the participants. Repeated (CUP_DUEL_FINISH_RACE_REPEAT, 50 for the review) - one 40P01 is a
 * crash of the race.
 */
it('never deadlocks the scoring of a duel-only day against the finish of the cup', function (int $scoreDelayUs, int $finishDelayUs, bool $slowInsert): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($duel, $a, $b, 3, 2);
    // A day with only a duel: neither has a row of the day yet - the scoring inserts both.
    expect(DB::table('cup_day_scores')->whereIn('participant_id', [$pa->id, $pb->id])->count())->toBe(0);
    $finishAt = CarbonImmutable::instance(raceCup($cup->id)->ends_at)->addMinutes(31);
    if ($slowInsert) {
        // The interleaving of the review, made certain: a BEFORE trigger holds the insert of the day row (before its foreign key is
        // checked) while the finish, started a moment later, takes the cup and waits for the participants.
        DB::unprepared('CREATE OR REPLACE FUNCTION test_slow_cup_day_score() RETURNS trigger AS $$ BEGIN PERFORM pg_sleep(0.4); RETURN NEW; END $$ LANGUAGE plpgsql');
        DB::unprepared('CREATE TRIGGER test_slow_cup_day_score BEFORE INSERT ON cup_day_scores FOR EACH ROW EXECUTE FUNCTION test_slow_cup_day_score()');
    }

    try {
        duelRaceValues(GameRace::run([
            duelRaceTask(function () use ($duel, $finishAt, $scoreDelayUs): ?string {
                usleep($scoreDelayUs);

                return app(CupDuelService::class)->score($duel->id, $finishAt)?->value;
            }),
            duelRaceTask(function () use ($cup, $finishAt, $finishDelayUs): bool {
                usleep($finishDelayUs);

                return app(CupLifecycleService::class)->finish($cup->id, $finishAt);
            }),
        ]));
    } finally {
        DB::reconnect();
        DB::unprepared('DROP TRIGGER IF EXISTS test_slow_cup_day_score ON cup_day_scores');
        DB::unprepared('DROP FUNCTION IF EXISTS test_slow_cup_day_score()');
    }

    $final = CupDuelKit::fresh($duel);
    expect(raceCup($cup->id)->status->value)->toBe('finished')
        ->and($final->status)->toBe(CupDuelStatus::Done);
    $points = DB::table('cup_day_scores')->where('participant_id', $pa->id)->value('duel_points');
    if ($final->end_reason === CupDuelEndReason::CupClosed) {
        expect($points)->toBeNull()->and(CupParticipant::query()->findOrFail($pa->id)->best_total)->toBe(0);
    } else {
        expect($points)->toBe(3)->and(CupParticipant::query()->findOrFail($pa->id)->final_rank)->toBe(1);
    }
})->with([
    'the scoring holds the participants, the finish takes the cup' => [0, 150_000, true],
    'the finish first' => [150_000, 0, true],
    'the same instant' => [0, 0, false],
])->repeat(max(1, (int) (getenv('CUP_DUEL_FINISH_RACE_REPEAT') ?: 5)));

it('credits a duel whose player leaves the chat at the instant the result is taken', function (): void {
    ['cup' => $cup, 'learners' => [$a, $b], 'participants' => [$pa, $pb]] = CupDuelKit::running(2);
    $duel = CupDuelKit::accept($b, CupDuelKit::challenge($a, $cup, $pb)->duel);
    CupDuelKit::playOut($duel, $a, $b, 2, 3);
    $groupId = $cup->telegram_group_id;
    $at = CarbonImmutable::now();

    duelRaceValues(GameRace::run([
        duelRaceTask(fn (): ?string => app(CupDuelService::class)->score($duel->id, $at)?->value),
        duelRaceTask(fn (): int => app(CupParticipationService::class)->onLeftChat(Learner::query()->findOrFail($b->id), TelegramGroup::query()->findOrFail($groupId), $at)),
    ]));

    expect(CupParticipant::query()->findOrFail($pb->id)->status->value)->toBe('left')
        ->and(DB::table('cup_day_scores')->where('participant_id', $pb->id)->value('duel_points'))->toBe(3)
        ->and(CupDuelKit::fresh($duel)->status)->toBe(CupDuelStatus::Done);
});

it('expires each open challenge once when two sweeps run at the same instant', function (): void {
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupDuelKit::running(5);
    foreach ($learners as $learner) {
        CupDuelKit::challenge($learner, $cup, public: true, now: $now);
    }
    $at = $now->addMinutes(11);

    $values = duelRaceValues(GameRace::run(array_fill(0, 2, duelRaceTask(fn (): int => app(CupDuelService::class)->sweep($at)['expired']))));

    expect(array_sum($values))->toBe(5)
        ->and(CupDuel::query()->where('status', 'expired')->count())->toBe(5)
        ->and(DB::table('cup_messages')->where('dedupe_key', 'like', 'duel:%:end')->count())->toBe(5);
    assertDuelInvariants();
});

it('makes one duel of the same request sent twice at the same instant', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupDuelKit::running(2);
    $requestId = (string) Str::uuid();
    $cupId = $cup->id;

    $values = duelRaceValues(GameRace::run(array_fill(0, 3, duelRaceTask(fn (): int => app(CupDuelService::class)
        ->challenge(Learner::query()->findOrFail($a->id), raceCup($cupId), null, false, PracticeMode::Pairs, $requestId, $now)->duel->id))));

    expect(array_unique($values))->toHaveCount(1)
        ->and(CupDuel::query()->count())->toBe(1);
});

it('keeps the public budget of a chat when several participants publish at the same instant', function (): void {
    config(['cup.duel.public_per_chat' => 3]);
    ['cup' => $cup, 'learners' => $learners, 'start' => $now] = CupDuelKit::running(6);
    config(['cup.duel.public_per_chat' => 3]);
    CupDuelKit::challenge($learners[0], $cup, public: true, now: $now);
    CupDuelKit::challenge($learners[1], $cup, public: true, now: $now);
    $cupId = $cup->id;

    $tasks = [];
    foreach (array_slice($learners, 2) as $learner) {
        $id = $learner->id;
        $tasks[] = duelRaceTask(fn (): int => app(CupDuelService::class)
            ->challenge(Learner::query()->findOrFail($id), raceCup($cupId), null, true, PracticeMode::Pairs, (string) Str::uuid(), $now)->duel->id);
    }
    $values = duelRaceValues(GameRace::run($tasks));

    expect(array_values(array_filter($values, 'is_int')))->toHaveCount(1)
        ->and(CupDuel::query()->where('is_public', true)->count())->toBe(3);
    foreach (array_filter($values, 'is_array') as $refused) {
        expect($refused['refused'])->toBe('cup_duel_limit');
    }
});
