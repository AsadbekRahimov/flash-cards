<?php

declare(strict_types=1);

/*
 * Real race: several taps on the same reminder question, in separate processes (own DB connections and Redis sockets),
 * released at the same instant. Exactly one may record the answer and its event; the others get the same verdict as a
 * repetition. The lock and the state live in Redis, so the cache of the children is the real one (the suite's default is
 * the per-process array store). Needs committed rows (DatabaseTruncation) and PostgreSQL, pcntl, posix and a Redis.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\ReminderQuizAnswerService;
use App\Domain\Learners\Services\ReminderQuizStore;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$reminderQuizOnPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $reminderQuizOnPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('records one answer for taps of the same question that race', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql), pcntl/posix and Redis: real parallel processes.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    ForkSafe::releaseConnections();
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * Runs every task in its own forked process, all released at the same instant; returns what each returned or threw.
 *
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function reminderQuizRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-quiz-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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

    ForkSafe::releaseConnections();
    DB::reconnect();

    return $results;
}

it('records one answer and one event when taps of the same question race, and gives every tap the same verdict', function (): void {
    config(['cache.default' => 'redis']);
    Cache::forgetDriver();

    $now = CarbonImmutable::now()->utc();
    $learner = Learner::factory()->create(['telegram_user_id' => 777901]);
    $store = app(ReminderQuizStore::class);
    $key = ReminderQuizStore::key($learner->id);
    Cache::forget($key);
    Cache::put($key, [
        'token' => 'Race123456', 'word_id' => 1, 'exercise' => 'meaning', 'word' => 'house', 'answer' => 'дом',
        'options' => ['дом', 'кот', 'лес', 'мир'], 'correct' => 0, 'kind' => 'review', 'due_count' => 3,
        'sent_at' => $now->getTimestamp(), 'expires_at' => $now->getTimestamp() + 3600, 'answered_at' => null, 'picked' => null,
    ], 3600);

    // Each process taps a different option: only the first one may count.
    $tasks = [];
    foreach ([0, 1, 2, 3, 1, 2] as $index) {
        $tasks[] = function () use ($learner, $index): array {
            $verdict = app(ReminderQuizAnswerService::class)->answer($learner, 'Race123456', $index);

            return ['correct' => $verdict->correct, 'repeated' => $verdict->repeated, 'status' => $verdict->status->value];
        };
    }

    $results = reminderQuizRunInParallel($tasks);
    config(['cache.default' => 'redis']);
    Cache::forgetDriver();

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $verdicts = array_column($results, 'result');
    $state = $store->get($learner->id);
    Cache::forget($key);

    expect(array_column($verdicts, 'status'))->each->toBe('accepted')
        ->and(array_filter($verdicts, fn (array $v): bool => ! $v['repeated']))->toHaveCount(1)
        // Everybody is told the verdict of the one counted pick.
        ->and(array_unique(array_column($verdicts, 'correct')))->toHaveCount(1)
        ->and($state['picked'])->not->toBeNull()
        ->and(($state['picked'] === 0))->toBe((bool) $verdicts[0]['correct'])
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizAnswered->value)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', ProductEventName::ReminderQuizAnswered->value)->sole()->props['picked'])->toBe($state['picked']);
});
