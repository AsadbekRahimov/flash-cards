<?php

declare(strict_types=1);

/*
 * A real race of "share" taps (docs/36 §1в п. 2, H3): forked processes, each with its own Redis and PostgreSQL connection, prepare the
 * same message at the same instant while the Bot API call is slow. The lock of SharePreparer lets one of them call Telegram; the others
 * wait and take its id from the cache. Needs committed rows (DatabaseTruncation) and a shared cache store (CACHE_STORE=redis).
 */

use App\Domain\Engagement\Enums\ShareKind;
use App\Domain\Engagement\Services\SharePreparer;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Learner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeTelegramClient;
use Tests\Support\ForkSafe;

$shareRaceReady = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql'
    && (getenv('CACHE_STORE') ?: 'array') === 'redis'
    && function_exists('pcntl_fork') && function_exists('posix_kill');

if (! $shareRaceReady) {
    it('races two share taps on one prepared message', function (): void {})
        ->skip('Requires PostgreSQL and Redis (DB_CONNECTION=pgsql, CACHE_STORE=redis) and pcntl.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config([
        'app.brand_logo' => null,
        'telegram.bot_username' => 'MuhrlaBot',
        'achievements.illustrations' => sys_get_temp_dir().'/lf-no-card-art',
        'billing.trial.days' => 14,
        'share.enabled' => true,
        'share.prepare.lock_wait_seconds' => 5,
        // The prepared message is a pure cache (docs/19 §3 rule 10): the forked taps must share it, as the two Redis stores of production do.
        'cache.pure_store' => config('cache.default'),
    ]);
    Storage::fake('public', ['url' => 'https://muhrla.test/storage']);
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, calls?: int, error?: string, message?: string}>
 */
function shareRaceInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-share-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = "{$dir}/go";
    $pids = [];

    foreach ($tasks as $i => $task) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork failed');
        }

        if ($pid === 0) {
            $outcome = [];
            try {
                ForkSafe::releaseConnections();
                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $result = $task();
                $telegram = app(TelegramClient::class);
                $outcome = ['ok' => true, 'result' => $result, 'calls' => $telegram instanceof FakeTelegramClient ? count($telegram->preparedMessages) : -1];
            } catch (Throwable $e) {
                $outcome = ['ok' => false, 'error' => $e::class, 'message' => $e->getMessage()];
            } finally {
                file_put_contents("{$dir}/{$i}.json", json_encode($outcome, JSON_THROW_ON_ERROR));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        $pids[] = $pid;
    }

    touch($go);

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];
    foreach (array_keys($tasks) as $i) {
        /** @var array{ok: bool, result?: mixed, calls?: int, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

it('calls Telegram once when three taps prepare the same message at the same instant', function (): void {
    $learner = Learner::factory()->create(['interface_locale' => 'ru', 'referral_code' => 'RACE01']);
    $telegram = app(TelegramClient::class);
    assert($telegram instanceof FakeTelegramClient);
    // A slow Bot API: the other taps arrive while the first one still waits for the answer.
    $telegram->beforePrepare = static fn () => usleep(400_000);

    $results = shareRaceInParallel(array_fill(0, 3, static function () use ($learner): array {
        $prepared = app(SharePreparer::class)->prepare(Learner::query()->findOrFail($learner->id), ShareKind::Invite, null);

        return ['id' => $prepared->id, 'cached' => $prepared->cached];
    }));

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }

    $ids = collect($results)->pluck('result.id')->unique()->values()->all();
    expect(collect($results)->sum('calls'))->toBe(1)
        ->and($ids)->toHaveCount(1)
        ->and(collect($results)->where('result.cached', false)->count())->toBe(1);
});
