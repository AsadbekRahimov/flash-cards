<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattlePhase;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Domain\Practice\Services\Battle\BattleReader;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Models\Battle;
use App\Models\BattleAnswer;
use App\Models\BattleParticipant;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ForkSafe;
use Tests\Support\Practice\BattleKit;

/*
 * docs/16 §9 rules 8-13, §14.2: the real races of the battle. Several OS processes, each with its own PostgreSQL connection,
 * hit the same match at the same instant: the `FOR UPDATE` of the `battles` row, the unique `battle_participants.active_learner_id`
 * and the unique `(battle_id, round, learner_id)` / `(battle_id, learner_id, request_id)` are what the assertions rely on, so
 * this file runs only on pgsql with pcntl. Every worker sets a lock timeout: a deadlock (PostgreSQL aborts one side, 40P01) or
 * a wait that never ends (55P03) comes back as a crash of the worker, never as a test that hangs. Data is committed
 * (DatabaseTruncation), the tables are truncated after each test.
 */

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }

    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * Runs each closure in a forked child with a fresh connection and a lock timeout, all released at the same instant; what they
 * gave back, in task order. A refusal of the battle is a result, not a crash: `['code' => ..., 'details' => ...]`.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, code?: string, details?: array<string, mixed>, error?: string}>
 */
function battleRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-battle-race-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $startAt = microtime(true) + 0.4;
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $out = ['ok' => false, 'error' => 'no result'];
            try {
                ForkSafe::releaseConnections();
                DB::reconnect();
                // A worker that waits more than 8 s is stuck (a deadlock is reported by PostgreSQL itself, sooner).
                DB::statement("SET lock_timeout = '8000'");
                while (microtime(true) < $startAt) {
                    usleep(500);
                }
                $out = ['ok' => true, 'value' => $task()];
            } catch (BattleException|PracticeException $e) {
                $out = ['ok' => false, 'code' => $e->errorCode, 'details' => $e->details];
            } catch (Throwable $e) {
                $out = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($out));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[$i] = $pid;
    }

    $results = [];
    foreach ($pids as $i => $pid) {
        pcntl_waitpid($pid, $status);
        $raw = @file_get_contents("{$dir}/{$i}.json");
        $results[$i] = $raw === false
            ? ['ok' => false, 'error' => 'worker died without a result']
            : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);

    DB::reconnect();

    return $results;
}

/** The workers that crashed (not the ones the battle refused). @param list<array<string, mixed>> $results @return list<string> */
function battleRaceCrashes(array $results): array
{
    return array_values(array_map('strval', array_column(array_filter($results, static fn (array $r): bool => isset($r['error'])), 'error')));
}

/** `ok` for a worker that succeeded, else its code. @param list<array<string, mixed>> $results @return list<string> */
function battleRaceOutcomes(array $results): array
{
    return array_map(static fn (array $r): string => $r['ok'] ? 'ok' : (string) ($r['code'] ?? 'crash'), $results);
}

function battleRaceLearner(int $id): Learner
{
    return Learner::query()->findOrFail($id);
}

/** @return list<array<string, mixed>> */
function battleRaceEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

/**
 * Plays `$rounds` rounds of a match that is on: I answer right, the friend wrong; each round is resolved by the second answer and
 * the clock is moved past its reveal so that the next one opens (the last one is left in its reveal).
 */
function battleRacePlay(Battle $battle, Learner $me, Learner $friend, int $rounds): void
{
    for ($round = 1; $round <= $rounds; $round++) {
        $element = BattleKit::element($battle, $round);
        BattleKit::answer($battle, $me, $round, BattleKit::correct($element));
        BattleKit::answer($battle, $friend, $round, BattleKit::wrong($element));
        if ($round < $rounds) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(4));
            BattleKit::reader()->poll(BattleKit::reader()->find($me, $battle->id), $me, CarbonImmutable::now());
        }
    }
}

it('lets one learner accept only one of two invitations that arrive at the same instant: one room, 409 battle_busy for the other, one active row', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $third = Learner::factory()->create(['interface_locale' => 'ru']);
    $withThird = Friendship::factory()->between($me, $third)->create();
    $fromFriend = BattleKit::invite($friend, $friendship, PracticeMode::Collocation, $course);
    $fromThird = BattleKit::invite($third, $withThird, PracticeMode::Pairs, $course);
    $meId = $me->id;

    $started = microtime(true);
    $results = battleRace([
        fn (): string => app(BattleService::class)->accept(battleRaceLearner($meId), $fromFriend->id)->status->value,
        fn (): string => app(BattleService::class)->accept(battleRaceLearner($meId), $fromThird->id)->status->value,
    ]);
    $elapsed = microtime(true) - $started;

    $outcomes = battleRaceOutcomes($results);
    $won = $outcomes[0] === 'ok' ? $fromFriend : $fromThird;
    $lost = $outcomes[0] === 'ok' ? $fromThird : $fromFriend;
    $loser = $results[$outcomes[0] === 'ok' ? 1 : 0];

    expect(battleRaceCrashes($results))->toBe([])
        ->and(array_count_values($outcomes))->toEqual(['ok' => 1, 'battle_busy' => 1])
        ->and($loser['details'] ?? null)->toBe(['battle_id' => $won->id])
        ->and(BattleKit::fresh($won)->status)->toBe(BattleStatus::Room)
        ->and(BattleKit::fresh($lost)->status)->toBe(BattleStatus::Invited)
        ->and(BattleParticipant::query()->where('active_learner_id', $meId)->count())->toBe(1)
        ->and(BattleParticipant::query()->where('active_learner_id', $meId)->sole()->battle_id)->toBe($won->id)
        ->and(battleRaceEvents('battle.accepted'))->toBe([['battle_id' => $won->id]])
        ->and($elapsed)->toBeLessThan(8.0); // nobody waited for a lock that never came
});

it('serialises an acceptance and a cancellation of the same invitation: one closed match, no deadlock, the events once', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $seen = [];

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $battle = BattleKit::invite($me, $friendship, PracticeMode::Collocation, $course);
        $meId = $me->id;
        $friendId = $friend->id;

        $results = battleRace([
            fn (): string => app(BattleService::class)->accept(battleRaceLearner($friendId), $battle->id)->status->value,
            fn (): string => app(BattleService::class)->cancel(battleRaceLearner($meId), $battle->id)->status->value,
        ]);
        $outcomes = battleRaceOutcomes($results);
        $seen[] = implode('/', $outcomes);

        // The acceptance wins: a room the challenger then leaves (closed / canceled, left = challenger); the cancellation wins:
        // the acceptance finds the match closed. Either way: closed / canceled, both released, one battle.closed.
        $row = BattleKit::fresh($battle);
        expect(battleRaceCrashes($results))->toBe([], "attempt {$attempt}")
            ->and($outcomes[1])->toBe('ok')
            ->and($outcomes[0])->toBeIn(['ok', 'battle_closed'])
            ->and([$row->status, $row->end_reason])->toBe([BattleStatus::Closed, BattleEndReason::Canceled])
            ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null])
            ->and($outcomes[0] === 'ok' ? ($row->meta['left'] ?? null) : ($results[0]['details'] ?? null))->toBe($outcomes[0] === 'ok' ? 'challenger' : ['end_reason' => 'canceled']);
    }

    expect(ProductEvent::query()->where('name', 'battle.closed')->count())->toBe(4)
        ->and(ProductEvent::query()->where('name', 'battle.accepted')->count())->toBe(count(array_filter($seen, fn (string $s): bool => str_starts_with($s, 'ok/'))));
});

it('gives a learner who sends three invitations at the same instant exactly one: the others are 409 battle_busy with its id', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $others = [$friendship];
    foreach (range(1, 2) as $i) {
        $others[] = Friendship::factory()->between($me, Learner::factory()->create(['interface_locale' => 'uz']))->create();
    }
    $meId = $me->id;
    $slug = $course->slug;

    $results = battleRace(array_map(
        fn (Friendship $pair): Closure => fn (): int => app(BattleService::class)->invite(battleRaceLearner($meId), $pair->id, PracticeMode::Sentence, $slug, null, null)->id,
        $others,
    ));

    $outcomes = battleRaceOutcomes($results);
    $winner = Battle::query()->sole();
    expect(battleRaceCrashes($results))->toBe([])
        ->and(array_count_values($outcomes))->toEqual(['ok' => 1, 'battle_busy' => 2])
        ->and(Battle::query()->count())->toBe(1)
        ->and(BattleParticipant::query()->where('active_learner_id', $meId)->count())->toBe(1)
        ->and(battleRaceEvents('battle.invited'))->toHaveCount(1);
    foreach ($results as $result) {
        if (! $result['ok']) {
            expect($result['details'] ?? null)->toBe(['battle_id' => $winner->id]);
        }
    }
});

it('takes the answers of both players at the same instant: one resolves the round, one reveal, two rows, version +2, the right scores', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    $element = BattleKit::element($battle, 1);
    $meId = $me->id;
    $friendId = $friend->id;
    $right = BattleKit::correct($element);
    $wrong = BattleKit::wrong($element);

    $results = battleRace([
        fn (): array => (array) json_decode(json_encode(app(BattleService::class)->answer(battleRaceLearner($meId), $battle->id, (string) Str::uuid(), 1, $right), JSON_THROW_ON_ERROR), true),
        fn (): array => (array) json_decode(json_encode(app(BattleService::class)->answer(battleRaceLearner($friendId), $battle->id, (string) Str::uuid(), 1, $wrong), JSON_THROW_ON_ERROR), true),
    ]);

    $row = BattleKit::fresh($battle);
    $resolved = array_map(fn (array $r): bool => (bool) ($r['value']['roundResolved'] ?? false), $results);
    expect(battleRaceCrashes($results))->toBe([])
        ->and(battleRaceOutcomes($results))->toBe(['ok', 'ok'])
        ->and(array_count_values(array_map('intval', $resolved)))->toEqual([0 => 1, 1 => 1]) // exactly one of them resolved the round
        ->and([$row->phase, $row->state_version, $row->current_round])->toBe([BattlePhase::Reveal, 6, 1])
        ->and($row->phase_ends_at?->toDateTimeString())->toBe('2026-10-30 10:00:07')
        ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe(2)
        ->and([$row->participant($meId)->score, $row->participant($friendId)->score])->toBe([100, 0])
        ->and(ksorted((array) $row->meta['answered']))->toBe(['challenger' => 1, 'opponent' => 1]);
});

it('never takes an answer AND writes a time-out for the same player when the answer and the sweeper meet at the deadline', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $meId = $me->id;
    $friendId = $friend->id;
    $seen = [];

    for ($attempt = 0; $attempt < 6; $attempt++) {
        CarbonImmutable::setTestNow(BattleKit::T0);
        $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
        $deadline = BattleKit::fresh($battle)->round_deadline_at ?? throw new RuntimeException('no deadline');
        BattleKit::bothPoll($battle, $me, $friend, $deadline->subSeconds(15)->toDateTimeString()); // both are here all along (a client polls every 2 s)
        BattleKit::bothPoll($battle, $me, $friend, $deadline->subSecond()->toDateTimeString());
        $right = BattleKit::correct(BattleKit::element($battle, 1));
        $answerAt = $deadline->addSecond();              // 1.0 s late: within the grace
        $sweepAt = $deadline->addMilliseconds(1600);     // the sweeper comes once the grace is over

        $results = battleRace([
            fn (): string => app(BattleService::class)->answer(battleRaceLearner($meId), $battle->id, (string) Str::uuid(), 1, $right, $answerAt)->verdict->value,
            fn (): int => app(BattleJanitor::class)->sweep($sweepAt),
        ]);
        $outcomes = battleRaceOutcomes($results);
        $seen[] = $outcomes[0];

        $mine = BattleAnswer::query()->where('battle_id', $battle->id)->where('learner_id', $meId)->get();
        $theirs = BattleAnswer::query()->where('battle_id', $battle->id)->where('learner_id', $friendId)->get();
        $row = BattleKit::fresh($battle);
        expect(battleRaceCrashes($results))->toBe([], "attempt {$attempt}")
            ->and($outcomes[1])->toBe('ok')
            ->and($mine)->toHaveCount(1, "attempt {$attempt}: one row for me, whoever came first")
            ->and($theirs)->toHaveCount(1)
            ->and($theirs->first()?->verdict)->toBe(Verdict::Timeout)
            ->and($row->phase)->toBe(BattlePhase::Reveal);
        if ($outcomes[0] === 'ok') {
            // The answer got the lock first: taken within the grace; the sweeper then timed out only the friend.
            expect($mine->first()?->verdict)->toBe(Verdict::Correct)
                ->and($row->participant($meId)->score)->toBe(100);
        } else {
            // The sweeper got the lock first: both timed out, the late answer finds the round closed.
            expect($outcomes[0])->toBe('round_closed')
                ->and($mine->first()?->verdict)->toBe(Verdict::Timeout)
                ->and($row->participant($meId)->score)->toBe(0);
        }

        app(BattleService::class)->surrender(battleRaceLearner($meId), $battle->id, $sweepAt); // frees both for the next attempt
    }

    expect(array_unique($seen))->each->toBeIn(['ok', 'round_closed']);
});

it('finalises a match exactly once when both players poll and the sweeper runs at the end of the last reveal: one battle.finished, one winner', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Collocation, $course);
    battleRacePlay($battle, $me, $friend, 5);
    $row = BattleKit::fresh($battle);
    expect([$row->current_round, $row->phase, $row->state_version])->toBe([5, BattlePhase::Reveal, 18]);
    $end = $row->phase_ends_at ?? throw new RuntimeException('no reveal end');
    CarbonImmutable::setTestNow($end);
    $meId = $me->id;
    $friendId = $friend->id;

    $results = battleRace([
        fn (): string => app(BattleReader::class)->poll(app(BattleReader::class)->find(battleRaceLearner($meId), $battle->id), battleRaceLearner($meId), $end)->status,
        fn (): string => app(BattleReader::class)->poll(app(BattleReader::class)->find(battleRaceLearner($friendId), $battle->id), battleRaceLearner($friendId), $end)->status,
        fn (): int => app(BattleJanitor::class)->sweep($end),
    ]);

    $row = BattleKit::fresh($battle);
    expect(battleRaceCrashes($results))->toBe([])
        ->and(battleRaceOutcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and([$results[0]['value'], $results[1]['value']])->toBe(['finished', 'finished'])
        ->and([$row->status, $row->end_reason, $row->winner_learner_id, $row->state_version])->toBe([BattleStatus::Finished, BattleEndReason::Completed, $meId, 19])
        ->and([$row->participant($meId)->score, $row->participant($friendId)->score])->toBe([500, 0])
        ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null])
        ->and(battleRaceEvents('battle.finished'))->toHaveCount(1)
        ->and(battleRaceEvents('battle.finished')[0])->toMatchArray(['battle_id' => $battle->id, 'end_reason' => 'completed', 'result' => 'challenger', 'rounds_played' => 5]);
});

it('lets exactly one of two rematches that cross go through, whatever the gap between them: the other is 409 battle_busy with the id of the first (Ж2, open_pair)', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $meId = $me->id;
    $friendId = $friend->id;
    $outcomes = [];

    foreach ([0.0, 0.005, 0.010, 0.0, 0.005, 0.010] as $attempt => $gap) {
        CarbonImmutable::setTestNow(BattleKit::T0);
        $source = BattleKit::countdown($me, $friend, $friendship, PracticeMode::Collocation, $course);
        app(BattleService::class)->surrender($me, $source->id);
        $sourceId = $source->id;

        $results = battleRace([
            fn (): int => app(BattleService::class)->invite(battleRaceLearner($meId), null, PracticeMode::Pairs, '', null, $sourceId)->id,
            function () use ($friendId, $sourceId, $gap): int {
                usleep((int) ($gap * 1_000_000));

                return app(BattleService::class)->invite(battleRaceLearner($friendId), null, PracticeMode::Pairs, '', null, $sourceId)->id;
            },
        ]);
        $open = Battle::query()->where('rematch_of_id', $sourceId)->whereIn('status', BattleStatus::nonTerminalValues())->get();
        $codes = battleRaceOutcomes($results);
        $outcomes[] = implode('/', $codes);
        $winner = $open->first();

        expect(battleRaceCrashes($results))->toBe([], "attempt {$attempt}")
            ->and($open)->toHaveCount(1, "attempt {$attempt}: ".json_encode($results))
            ->and(array_count_values($codes))->toEqual(['ok' => 1, 'battle_busy' => 1])
            ->and($winner?->open_pair)->toBe(Battle::pairKey($meId, $friendId))
            ->and(Battle::query()->where('rematch_of_id', $sourceId)->count())->toBe(1); // the loser's INSERT was refused: no second row, no second event
        foreach ($results as $result) {
            if ($result['ok']) {
                expect($result['value'])->toBe($winner?->id);
            } else {
                expect($result['details'] ?? null)->toBe(['battle_id' => $winner?->id]); // the loser is told which one to open and accept
            }
        }

        app(BattleService::class)->cancel(battleRaceLearner((int) $winner?->challenger_id), (int) $winner?->id);
    }

    expect(ProductEvent::query()->where('name', 'battle.invited')->where('props->rematch', true)->count())->toBe(6)
        ->and(BattleParticipant::query()->whereNotNull('active_learner_id')->count())->toBe(0);
});

it('settles a surrender and the opponent answer that meet: one finished match with the opponent as the winner, no deadlock', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $meId = $me->id;
    $friendId = $friend->id;

    for ($attempt = 0; $attempt < 4; $attempt++) {
        CarbonImmutable::setTestNow(BattleKit::T0);
        $battle = BattleKit::playing($me, $friend, $friendship, PracticeMode::Pairs, $course);
        $right = BattleKit::correct(BattleKit::element($battle, 1));

        $results = battleRace([
            fn (): string => app(BattleService::class)->surrender(battleRaceLearner($meId), $battle->id)->end_reason?->value ?? '',
            fn (): string => app(BattleService::class)->answer(battleRaceLearner($friendId), $battle->id, (string) Str::uuid(), 1, $right)->verdict->value,
        ]);
        $outcomes = battleRaceOutcomes($results);

        $row = BattleKit::fresh($battle);
        expect(battleRaceCrashes($results))->toBe([], "attempt {$attempt}")
            ->and($outcomes[0])->toBe('ok')
            ->and($results[0]['value'])->toBe('surrender')
            ->and($outcomes[1])->toBeIn(['ok', 'battle_closed'])
            ->and([$row->status, $row->end_reason, $row->winner_learner_id, $row->meta['rounds_played']])->toBe([BattleStatus::Finished, BattleEndReason::Surrender, $friendId, 0])
            ->and([$row->participant($meId)->score, $row->participant($friendId)->score])->toBe([0, 0]) // an answer to an unresolved round counts for nothing
            ->and(BattleAnswer::query()->where('battle_id', $battle->id)->count())->toBe($outcomes[1] === 'ok' ? 1 : 0)
            ->and($row->participants->pluck('active_learner_id')->all())->toBe([null, null]);
    }

    expect(battleRaceEvents('battle.finished'))->toHaveCount(4);
});
