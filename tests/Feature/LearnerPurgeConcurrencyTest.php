<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerPurgeService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\AuditLog;
use App\Models\Learner;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

/*
 * Real races of the final deletion on PostgreSQL (docs/03 §8 rule 2): forked workers with their own connections,
 * released at the same instant. The array cache of the tests is per process, so the purge lock does not protect
 * here — what the race checks is the second guard, the row lock with the re-check of the due date.
 *
 * Needs committed rows (children cannot see the parent's open transaction): DatabaseTruncation, not RefreshDatabase.
 * sqlite has no row locks and `:memory:` is per connection: skipped there.
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

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-03 03:30:00', 'UTC'));
    config()->set('learners.purge.after_days', 30);
    config()->set('learners.purge.batch', 2);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * Runs each closure in a forked child with a fresh connection, all released at the same instant; what they gave back,
 * in task order (`['ok' => false, 'error' => ...]` for a crash).
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, error?: string}>
 */
function purgeRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-purge-race-'.bin2hex(random_bytes(4));
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
        $results[$i] = $raw === false ? ['ok' => false, 'error' => 'worker died without a result'] : json_decode($raw, true);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);
    DB::reconnect();

    return $results;
}

it('deletes every due learner exactly once when two runs overlap', function (): void {
    $ids = [];
    foreach (range(1, 6) as $n) {
        $learner = Learner::factory()->create(['telegram_user_id' => 880_000 + $n]);
        WordRepetition::factory()->create(['learner_id' => $learner->id]);
        $learner->delete();
        Learner::withTrashed()->whereKey($learner->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(30 + $n)]);
        $ids[] = $learner->id;
    }

    $results = purgeRace([
        fn (): array => app(LearnerPurgeService::class)->purgeDue()->toArray(),
        fn (): array => app(LearnerPurgeService::class)->purgeDue()->toArray(),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue($result['error'] ?? '');
    }
    $purged = array_sum(array_map(static fn (array $r): int => (int) $r['value']['purged'], $results));

    expect($purged)->toBe(6)
        ->and(Learner::withTrashed()->whereIn('id', $ids)->count())->toBe(0)
        ->and(WordRepetition::query()->whereIn('learner_id', $ids)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', LearnerPurgeService::ACTION_PURGED)->count())->toBe(6)
        ->and(AuditLog::query()->where('action', LearnerPurgeService::ACTION_PURGED)->distinct()->count('entity_id'))->toBe(6);
});

it('leaves exactly one learner per Telegram user when the purge and their own /start collide', function (): void {
    $learner = Learner::factory()->create(['telegram_user_id' => 880_100, 'first_name' => 'Racer']);
    $learner->delete();
    Learner::withTrashed()->whereKey($learner->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(31)]);

    $results = purgeRace([
        fn (): bool => app(LearnerPurgeService::class)->purge($learner->id),
        fn (): int => app(LearnerRegistrar::class)->fromTelegramUser(['id' => 880_100, 'first_name' => 'Racer'])->id,
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue($result['error'] ?? '');
    }
    [$purge, $start] = $results;
    $rows = Learner::withTrashed()->where('telegram_user_id', 880_100)->get();

    // Either the /start came first (restored, nothing purged) or the purge did (a brand-new row from the same /start).
    expect($rows)->toHaveCount(1)
        ->and($rows->first()?->trashed())->toBeFalse()
        ->and($rows->first()?->id)->toBe($start['value'])
        ->and($purge['value'])->toBe($start['value'] !== $learner->id)
        ->and(AuditLog::query()->where('action', LearnerPurgeService::ACTION_PURGED)->count())->toBe($purge['value'] ? 1 : 0);
});
