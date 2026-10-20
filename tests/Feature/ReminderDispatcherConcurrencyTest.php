<?php

declare(strict_types=1);

/*
 * Real race on PostgreSQL: several dispatcher instances (forked processes, own DB
 * connections, released at the same instant) claim the same due learners. The
 * conditional UPDATE on `next_reminder_at` must hand every learner to exactly one
 * instance. Needs committed rows (children cannot see the parent's transaction), so
 * DatabaseTruncation instead of RefreshDatabase; sqlite `:memory:` is one connection
 * without row locks — skipped there.
 */

use App\Domain\Learners\Services\ReminderDispatcher;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('claims every due learner exactly once across parallel dispatcher instances', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel connections and row-level UPDATE semantics.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * Runs every task in its own forked process (own DB connection), all released at the
 * same instant; returns what each returned or threw.
 *
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function remindersRunInParallel(array $tasks): array
{
    // The children must not inherit (and later close) the parent's socket.
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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
                while (microtime(true) < $startAt) {
                    usleep(500);
                }
                $out = ['ok' => true, 'result' => $task()];
            } catch (Throwable $e) {
                $out = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($out));
                DB::disconnect();
                // Skip PHPUnit's shutdown handlers and destructors in the child.
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

it('claims every due learner exactly once across parallel dispatcher instances', function (): void {
    config(['learning.reminders.batch_size' => 7]); // several chunks per instance, interleaved with the others
    $now = CarbonImmutable::parse('2026-10-18 14:05:00', 'UTC');
    $dueIds = Learner::factory()->count(40)->create([
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'next_reminder_at' => $now->subMinutes(5),
    ])->pluck('id')->all();
    $notYet = Learner::factory()->create([
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'next_reminder_at' => $now->addMinute(),
    ]);

    $results = remindersRunInParallel(array_fill(0, 4, function () use ($now): array {
        // Each instance counts what IT claimed; the queue is faked per process so the
        // test does not depend on a worker.
        Bus::fake();

        return app(ReminderDispatcher::class)->dispatchDue($now)->toArray();
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $claimed = array_sum(array_map(fn (array $r): int => (int) $r['result']['claimed'], $results));
    $queued = array_sum(array_map(fn (array $r): int => (int) $r['result']['queued'], $results));
    expect($claimed)->toBe(40)
        ->and($queued)->toBe(40);

    // Every due learner moved to tomorrow 19:00 Tashkent exactly once; the other one is untouched.
    $pointers = Learner::query()->whereIn('id', $dueIds)->pluck('next_reminder_at', 'id');
    expect($pointers)->toHaveCount(40);
    foreach ($pointers as $pointer) {
        expect($pointer?->utc()->toDateTimeString())->toBe('2026-10-19 14:00:00');
    }
    expect($notYet->fresh()?->next_reminder_at?->utc()->toDateTimeString())->toBe('2026-10-18 14:06:00');

    // A later run has nothing left to claim.
    Bus::fake();
    expect(app(ReminderDispatcher::class)->dispatchDue($now)->claimed)->toBe(0);
});

it('backfills a missing pointer exactly once when instances race', function (): void {
    $now = CarbonImmutable::parse('2026-10-18 10:00:00', 'UTC');
    Learner::factory()->count(25)->create([
        'timezone' => 'Asia/Tashkent',
        'reminder_time' => '19:00',
        'next_reminder_at' => null,
    ]);

    $results = remindersRunInParallel(array_fill(0, 3, function () use ($now): int {
        Bus::fake();

        return app(ReminderDispatcher::class)->backfillMissing($now);
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    expect(array_sum(array_column($results, 'result')))->toBe(25)
        ->and(Learner::query()->whereNull('next_reminder_at')->count())->toBe(0)
        ->and(Learner::query()->where('next_reminder_at', '2026-10-18 14:00:00')->count())->toBe(25);
});
