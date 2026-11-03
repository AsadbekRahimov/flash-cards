<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\Game\GameRace;

/*
 * The race harness itself (tests/Support/Game/GameRace.php): a worker that never returns must not hold the suite - the parent gives
 * up at the deadline, kills it and reports a clear error, while the workers that did return keep their results.
 */

if ((getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql') {
    uses(DatabaseTruncation::class);
}

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: the harness reconnects every worker to it.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
});

afterEach(function (): void {
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
    }
});

it('kills a worker that never returns at the deadline and reports it, keeping the results of the others', function (): void {
    $startedAt = microtime(true);

    $results = GameRace::run([
        fn (): int => 1,
        function (): int {
            sleep(3600);

            return 2;
        },
    ], timeoutSeconds: 2);

    expect(microtime(true) - $startedAt)->toBeLessThan(10.0)
        ->and($results[0])->toBe(['ok' => true, 'value' => 1])
        ->and($results[1])->toBe(['ok' => false, 'error' => 'worker timed out after 2 s'])
        ->and(GameRace::crashes($results))->toBe(['worker timed out after 2 s'])
        ->and(GameRace::outcomes($results))->toBe(['crash', 'ok']);
});
