<?php

declare(strict_types=1);

use App\Domain\Game\Enums\BoosterEffect;
use App\Domain\Game\Services\Runs\RunService;
use App\Models\GameBoosterUse;
use App\Models\GameInventoryItem;
use App\Models\GameItem;
use App\Models\GameRun;
use App\Models\GameRunAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Game\GameKit;
use Tests\Support\Game\GameRace;

/*
 * docs/18 §12.5.1, §12.5.4 (G2.5b part 2): the real races of the boosters. Several OS processes, each with its own PostgreSQL connection,
 * use a booster of the same run at the same instant. What the assertions rely on: the `FOR UPDATE` of the run (the tabs of a learner
 * are serialised there, so the limit of the week and "one booster of a kind per run" are decided on the final state), the atomic
 * `qty - 1 where qty > 0` of the inventory, and the unique `(run_id, item_id, element_id)` below them. Runs only on pgsql with pcntl
 * (the harness: Tests\Support\Game\GameRace). Data is committed (DatabaseTruncation).
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

    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    GameKit::openAccess();
    GameKit::noEvents();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * A learner with a started run and `$qty` of each of the boosters.
 *
 * @return array{0: int, 1: int, 2: GameItem, 3: GameItem} the learner, the run, the extra flask, the second chance
 */
function boosterRaceWorld(int $qty): array
{
    [$learner, $course, $lesson] = GameKit::stop(8);
    $flask = GameItem::factory()->booster(BoosterEffect::ExtraFlask)->create(['key' => 'booster_extra_flask']);
    $chance = GameItem::factory()->booster(BoosterEffect::SecondChance)->create(['key' => 'booster_second_chance']);
    foreach ([$flask, $chance] as $item) {
        GameInventoryItem::factory()->create(['learner_id' => $learner->id, 'item_id' => $item->id, 'qty' => $qty]);
    }
    $run = app(RunService::class)->start($learner, $course->slug, $lesson->id);

    return [$learner->id, $run->id, $flask, $chance];
}

it('spends the last booster of a kind once when two tabs of the run use it together: one wins, qty 0, one row of use, one attempt, one event', function (): void {
    [$learnerId, $runId, $flask] = boosterRaceWorld(1);

    $results = GameRace::run(array_map(
        fn (int $i): Closure => fn (): int => app(RunService::class)->booster($runId, GameRace::learner($learnerId), (string) Str::uuid(), 'booster_extra_flask')->flasks,
        range(1, 2),
    ));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['booster_already_used', 'ok'])
        ->and(GameInventoryItem::query()->where('item_id', $flask->id)->value('qty'))->toBe(0)
        ->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(GameRunAttempt::query()->count())->toBe(1)
        ->and(GameRace::events('game.booster_used'))->toHaveCount(1);
    $run = GameRun::query()->findOrFail($runId);
    expect($run->flasks)->toBe(4)->and($run->flasks_max)->toBe(4);
    $winner = collect($results)->first(static fn (array $r): bool => $r['ok']);
    expect($winner['value'])->toBe(4);
});

it('spends a booster once when five tabs send the same request_id: the same answer to all, qty down by one', function (): void {
    [$learnerId, $runId, $flask] = boosterRaceWorld(3);
    $requestId = (string) Str::uuid();

    $results = GameRace::run(array_fill(0, 5, fn (): int => app(RunService::class)->booster($runId, GameRace::learner($learnerId), $requestId, 'booster_extra_flask')->flasks));

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(array_fill(0, 5, 'ok'))
        ->and(array_unique(array_map(static fn (array $r): int => $r['value'], $results)))->toBe([4])
        ->and(GameInventoryItem::query()->where('item_id', $flask->id)->value('qty'))->toBe(2)
        ->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(GameRunAttempt::query()->count())->toBe(1)
        ->and(GameRace::events('game.booster_used'))->toHaveCount(1);
});

it('holds the limit of the week under the lock of the run: with one use a week, two tabs with two different boosters get one use, the other 409 booster_limit_reached', function (): void {
    GameKit::limits(null, null, 1);
    [$learnerId, $runId, $flask, $chance] = boosterRaceWorld(1);

    $results = GameRace::run([
        fn (): int => app(RunService::class)->booster($runId, GameRace::learner($learnerId), (string) Str::uuid(), 'booster_extra_flask')->flasks,
        fn (): int => app(RunService::class)->booster($runId, GameRace::learner($learnerId), (string) Str::uuid(), 'booster_second_chance')->flasks,
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameRace::outcomes($results))->toBe(['booster_limit_reached', 'ok'])
        ->and(GameBoosterUse::query()->count())->toBe(1)
        ->and(GameRunAttempt::query()->count())->toBe(1)
        // the loser kept its booster: exactly one of the two is spent
        ->and((int) GameInventoryItem::query()->whereIn('item_id', [$flask->id, $chance->id])->sum('qty'))->toBe(1);
    foreach ($results as $r) {
        if (! $r['ok']) {
            expect($r['details'])->toMatchArray(['limit' => 1, 'used' => 1]);
        }
    }
});

it('does not take a booster that another transaction has just taken: an admin revoke and a use at the same instant leave qty at 0, never below', function (): void {
    [$learnerId, $runId, $flask] = boosterRaceWorld(1);

    $results = GameRace::run([
        fn (): int => app(RunService::class)->booster($runId, GameRace::learner($learnerId), (string) Str::uuid(), 'booster_extra_flask')->flasks,
        fn (): int => GameInventoryItem::query()->where('learner_id', $learnerId)->where('item_id', $flask->id)->where('qty', '>', 0)->decrement('qty'),
    ]);

    expect(GameRace::crashes($results))->toBe([])
        ->and(GameInventoryItem::query()->where('item_id', $flask->id)->value('qty'))->toBe(0)
        ->and(GameBoosterUse::query()->count())->toBe(count(array_filter($results, static fn (array $r): bool => $r['ok'] && is_int($r['value']) && $r['value'] === 4)));
});
