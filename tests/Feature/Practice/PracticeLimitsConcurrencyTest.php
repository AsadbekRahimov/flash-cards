<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\BattleEndReason;
use App\Domain\Practice\Enums\BattleStatus;
use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Exceptions\BattleException;
use App\Domain\Practice\Exceptions\PracticeException;
use App\Domain\Practice\Services\Battle\BattleJanitor;
use App\Domain\Practice\Services\Battle\BattleService;
use App\Domain\Practice\Services\PracticeAccess;
use App\Domain\Practice\Services\PracticeLimits;
use App\Domain\Practice\Services\PracticeSessionService;
use App\Models\Battle;
use App\Models\BattleParticipant;
use App\Models\Friendship;
use App\Models\Learner;
use App\Models\PracticeItem;
use App\Models\PracticeSession;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ForkSafe;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/18 G1, docs/16 §9 rule 16: the races for the LAST place of a Free limit. Several OS processes, each with its own PostgreSQL
 * connection, create for the same learner at the same instant: the `FOR NO KEY UPDATE` of the learner's row - the first lock of the
 * creating transaction - is what the assertions rely on (the count under it sees the winner's row), so this file runs only on pgsql
 * with pcntl. Every worker sets a lock timeout: a deadlock (40P01) or a wait that never ends (55P03) is a crash, never a hang. Data
 * is committed (DatabaseTruncation), the tables are truncated after each test.
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

    CarbonImmutable::setTestNow('2026-10-30 10:00:00');
    PracticeKit::openAccess();
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
 * Runs each closure in a forked child with a fresh connection and a lock timeout, all released at the same instant; what they gave
 * back, in task order. A refusal (practice or battle) is a result, not a crash: `['code' => ..., 'details' => ...]`.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, code?: string, details?: array<string, mixed>, error?: string}>
 */
function limitsRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-limits-race-'.bin2hex(random_bytes(4));
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

/** The workers that crashed (not the ones that were refused). @param list<array<string, mixed>> $results @return list<string> */
function limitsRaceCrashes(array $results): array
{
    return array_values(array_map('strval', array_column(array_filter($results, static fn (array $r): bool => isset($r['error'])), 'error')));
}

/** `ok` for a worker that succeeded, else its code. @param list<array<string, mixed>> $results @return list<string> */
function limitsRaceOutcomes(array $results): array
{
    return array_map(static fn (array $r): string => $r['ok'] ? 'ok' : (string) ($r['code'] ?? 'crash'), $results);
}

function limitsRaceLearner(int $id): Learner
{
    return Learner::query()->findOrFail($id);
}

/** @return list<array<string, mixed>> */
function limitsRaceEvents(string $name): array
{
    return ProductEvent::query()->where('name', $name)->orderBy('id')->get()->map(fn (ProductEvent $e): array => (array) $e->props)->all();
}

it('gives the last session of the day to exactly one of three tabs that open at the same instant', function (): void {
    PracticeKit::limits(2, 1);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$course] = PracticeKit::course();
    $learnerId = Learner::factory()->create()->id;
    $slug = $course->slug;

    // One of the two places is taken already; the tabs replace the unfinished session, so only the limit stands in their way.
    app(PracticeSessionService::class)->open(limitsRaceLearner($learnerId), PracticeMode::Collocation, $slug, null, 'hub', false, null);

    $results = limitsRace(array_fill(0, 3, fn (): int => app(PracticeSessionService::class)->open(
        limitsRaceLearner($learnerId), PracticeMode::Collocation, $slug, null, 'hub', true, null,
    )->id));

    $opened = array_values(array_filter($results, fn (array $r): bool => $r['ok']));
    $refused = array_values(array_filter($results, fn (array $r): bool => ! $r['ok']));

    expect(limitsRaceCrashes($results))->toBe([])
        ->and($opened)->toHaveCount(1)
        ->and(array_column($refused, 'code'))->toBe(['practice_limit_reached', 'practice_limit_reached'])
        // The losers counted AFTER the winner's commit: they saw both places taken.
        ->and(array_map(fn (array $r): mixed => $r['details']['used'] ?? null, $refused))->toBe([2, 2])
        ->and(PracticeSession::query()->where('learner_id', $learnerId)->count())->toBe(2)
        ->and(PracticeSession::query()->where('active_learner_id', $learnerId)->count())->toBe(1)
        ->and(limitsRaceEvents('practice.session_started'))->toHaveCount(2)
        ->and(limitsRaceEvents('practice.limit_reached'))->toBe([['kind' => 'sessions', 'limit' => 2], ['kind' => 'sessions', 'limit' => 2]]);
});

it('gives the one battle call of the day to exactly one of two invitations sent at the same instant to different friends', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    PracticeKit::limits(null, 1); // after world(): it lifts the limits
    $third = Learner::factory()->create(['interface_locale' => 'uz']);
    $withThird = Friendship::factory()->between($me, $third)->create();
    $meId = $me->id;
    $slug = $course->slug;
    $pairs = [$friendship->id, $withThird->id];

    $results = limitsRace(array_map(
        fn (int $pair): Closure => fn (): int => app(BattleService::class)->invite(limitsRaceLearner($meId), $pair, PracticeMode::Collocation, $slug, null, null)->id,
        $pairs,
    ));

    $outcomes = limitsRaceOutcomes($results);
    sort($outcomes);

    // The loser is refused by the LIMIT (the count under the learner's lock), not by the unique `active_learner_id` (409 battle_busy):
    // the limit is decided before the rows are written, and neither invitation waited on the other's index entry.
    expect(limitsRaceCrashes($results))->toBe([])
        ->and($outcomes)->toBe(['ok', 'practice_limit_reached'])
        ->and(Battle::query()->where('challenger_id', $meId)->count())->toBe(1)
        ->and(BattleParticipant::query()->where('active_learner_id', $meId)->count())->toBe(1)
        ->and(limitsRaceEvents('battle.invited'))->toHaveCount(1)
        ->and(limitsRaceEvents('practice.limit_reached'))->toBe([['kind' => 'battles', 'limit' => 1]]);
});

it('does not deadlock a Free opening against the lock of the learner row the training picker takes, nor against a battle of the same learner', function (): void {
    PracticeKit::limits(2, 1);
    PracticeKit::fake(PracticeMode::Collocation, candidates: 6);
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    PracticeKit::limits(2, 1);
    $meId = $me->id;
    $friendId = $friend->id;
    $slug = $course->slug;
    $pair = $friendship->id;

    // The friend invites me while I open a session and take the learner lock the way the picker does: three transactions, one
    // learner row each, no cycle (docs/16 §9 rule 16).
    $results = limitsRace([
        fn (): int => app(PracticeSessionService::class)->open(limitsRaceLearner($meId), PracticeMode::Collocation, $slug, null, 'hub', false, null)->id,
        fn (): int => app(BattleService::class)->invite(limitsRaceLearner($friendId), $pair, PracticeMode::Collocation, $slug, null, null)->id,
        function () use ($meId): int {
            return DB::transaction(function () use ($meId): int {
                $lock = Learner::query()->whereKey($meId);
                (DB::connection()->getDriverName() === 'pgsql' ? $lock->lock('for no key update') : $lock->lockForUpdate())->value('id');
                usleep(200_000);

                return 1;
            });
        },
    ]);

    expect(limitsRaceCrashes($results))->toBe([])
        ->and(limitsRaceOutcomes($results))->toBe(['ok', 'ok', 'ok'])
        ->and(PracticeSession::query()->where('learner_id', $meId)->count())->toBe(1)
        ->and(Battle::query()->where('challenger_id', $friendId)->count())->toBe(1);
});

it('decides accept ∥ invite of one learner by the index, with the limits on or off: one wins, the other is 409 battle_busy - never the limit', function (bool $limits): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    if ($limits) {
        PracticeKit::limits(2, 1);
    }
    $third = Learner::factory()->create(['interface_locale' => 'ru']);
    $withThird = Friendship::factory()->between($me, $third)->create();
    $fromFriend = BattleKit::invite($friend, $friendship, PracticeMode::Collocation, $course);
    $meId = $me->id;
    $slug = $course->slug;
    $pair = $withThird->id;

    $results = limitsRace([
        fn (): string => app(BattleService::class)->accept(limitsRaceLearner($meId), $fromFriend->id)->status->value,
        fn (): int => app(BattleService::class)->invite(limitsRaceLearner($meId), $pair, PracticeMode::Collocation, $slug, null, null)->id,
    ]);
    $outcomes = limitsRaceOutcomes($results);
    sort($outcomes);

    // The acceptance takes no lock of the learner's row; the invitation takes it first and then meets the unique `active_learner_id`
    // of whichever came first. Neither waits for what the other holds (docs/16 §9 rule 16), and the loser is told it is busy.
    expect(limitsRaceCrashes($results))->toBe([])
        ->and($outcomes)->toBe(['battle_busy', 'ok'])
        ->and(BattleParticipant::query()->where('active_learner_id', $meId)->count())->toBe(1)
        ->and(limitsRaceEvents('practice.limit_reached'))->toBe([]);
})->with(['limits off' => [false], 'limits on' => [true]]);

it('lets a new call through when the learner\'s own invitation has run out and the sweeper closes it at the same instant, with the limits on or off', function (bool $limits): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    if ($limits) {
        PracticeKit::limits(2, 1);
    }
    $third = Learner::factory()->create(['interface_locale' => 'ru']);
    $withThird = Friendship::factory()->between($me, $third)->create();
    $dead = BattleKit::invite($me, $friendship, PracticeMode::Collocation, $course);
    $later = CarbonImmutable::parse('2026-10-30 10:00:00')->addMinutes(6);
    CarbonImmutable::setTestNow($later);
    $meId = $me->id;
    $slug = $course->slug;
    $pair = $withThird->id;

    $results = limitsRace([
        fn (): int => app(BattleService::class)->invite(limitsRaceLearner($meId), $pair, PracticeMode::Collocation, $slug, null, null, $later)->id,
        fn (): int => app(BattleJanitor::class)->sweep($later),
    ]);

    // The dead invitation is not a call of the day any more (limits on), so the claim passes; the row it still holds is closed by
    // whoever gets to it first - the sweeper, or the call itself on its way (Ж1) - exactly once, and the call goes through.
    $state = app(PracticeAccess::class)->state(limitsRaceLearner($meId));
    expect(limitsRaceCrashes($results))->toBe([])
        ->and($results[0]['ok'] ?? false)->toBeTrue()
        ->and(BattleKit::fresh($dead)->end_reason)->toBe(BattleEndReason::InviteExpired)
        ->and(Battle::query()->where('challenger_id', $meId)->whereIn('status', BattleStatus::nonTerminalValues())->count())->toBe(1)
        ->and(limitsRaceEvents('battle.closed'))->toHaveCount(1)
        ->and(app(PracticeLimits::class)->describe(limitsRaceLearner($meId), $state, null, $later)['battles']['used'] ?? null)->toBe($limits ? 1 : null);
})->with(['limits off' => [false], 'limits on' => [true]]);

it('gives the one free retry of a source to exactly one of three tabs that retry it at the same instant: the second takes the last place, the third is refused', function (): void {
    PracticeKit::limits(2, 1);
    [$course, $lesson] = PracticeKit::course();
    $learner = Learner::factory()->create();
    $words = PracticeKit::words($lesson, 3, $learner);
    foreach (range(1, 5) as $n) {
        PracticeItem::factory()->sentence()->published()->forLesson($lesson)->targeting($words)->create();
    }
    $headers = PracticeKit::headers($learner);
    $source = test()->withHeaders($headers)->postJson('/api/twa/practice/sessions', ['mode' => 'sentence', 'course' => $course->slug, 'lesson_id' => $lesson->id])->assertCreated()->json();
    for ($i = 0; $source['status'] === 'active' && $i < 10; $i++) {
        PracticeKit::resetLimiters($learner);
        $source = test()->withHeaders($headers)->postJson("/api/twa/practice/sessions/{$source['id']}/answer", [
            'request_id' => (string) Str::uuid(), 'element_id' => $source['current']['id'], 'answer' => ['skip' => true],
        ])->assertOk()->json('session');
    }
    expect($source['status'])->toBe('finished');
    $learnerId = $learner->id;
    $sourceId = (int) $source['id'];
    $slug = $course->slug;

    $results = limitsRace(array_fill(0, 3, fn (): int => app(PracticeSessionService::class)->open(
        limitsRaceLearner($learnerId), PracticeMode::Sentence, $slug, null, 'retry', true, $sourceId,
    )->id));
    $outcomes = limitsRaceOutcomes($results);
    sort($outcomes);

    // Under the learner's lock the tabs go one by one: the first finds no retry of the source and is free, the second finds one and
    // takes the second place of the day (the source took the first), the third finds the day full.
    $state = app(PracticeAccess::class)->state(limitsRaceLearner($learnerId));
    expect(limitsRaceCrashes($results))->toBe([])
        ->and($outcomes)->toBe(['ok', 'ok', 'practice_limit_reached'])
        ->and(PracticeSession::query()->where('retry_of_session_id', $sourceId)->count())->toBe(2)
        ->and(app(PracticeLimits::class)->describe(limitsRaceLearner($learnerId), $state)['sessions']['used'] ?? null)->toBe(2)
        ->and(limitsRaceEvents('practice.limit_reached'))->toBe([['kind' => 'sessions', 'limit' => 2]]);
});
