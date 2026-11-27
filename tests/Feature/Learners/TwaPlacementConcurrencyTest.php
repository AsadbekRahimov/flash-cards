<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL around the placement test of the onboarding (docs/40 §2.1a): two devices starting a test while a step is saved
 * (both write `meta` under the row lock: neither loses the other's keys), parallel answers to one set (one scoring, every answer gets the
 * same result), and an answer racing a new start. Each contender is a forked process with its own connection; committed rows:
 * DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Learners\DTO\PlacementResult;
use App\Domain\Learners\Exceptions\TwaOnboardingException;
use App\Domain\Learners\Services\PlacementTestService;
use App\Domain\Learners\Services\TwaOnboardingService;
use App\Models\Learner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the placement test on PostgreSQL', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config(['learners.goals' => ['general'], 'learners.daily_goal_options' => [10, 20, 40]]);
    foreach (['A1', 'B1', 'B2', 'C1'] as $level) {
        Fx::course([1 => [1 => 6, 2 => 6]], ['slug' => 'english-'.strtolower($level), 'exam_tag' => 'general', 'level' => $level]);
    }
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function plRaceInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-pl-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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
                DB::statement("SET lock_timeout = '10s'");

                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }

                $outcome = ['ok' => true, 'result' => $task()];
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
        /** @var array{ok: bool, result?: mixed, error?: string, message?: string} $decoded */
        $decoded = json_decode((string) file_get_contents("{$dir}/{$i}.json"), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
        unlink("{$dir}/{$i}.json");
    }
    unlink($go);
    rmdir($dir);

    return $results;
}

/** @return list<array{id: int, word_id: int, level: string, correct: int, n: int}> */
function plRaceItems(int $learnerId): array
{
    /** @var list<array{id: int, word_id: int, level: string, correct: int, n: int}> */
    return Learner::query()->findOrFail($learnerId)->meta['twa_onboarding']['placement']['items'] ?? [];
}

it('keeps every key of meta when two starts and a step of the onboarding write at once', function (): void {
    $learner = Learner::factory()->create(['meta' => ['locale_chosen' => true]]);

    $results = plRaceInParallel([
        fn (): int => count(app(PlacementTestService::class)->start(Learner::query()->findOrFail($learner->id))->questions),
        fn (): ?string => app(TwaOnboardingService::class)->saveStep(Learner::query()->findOrFail($learner->id), 'goals', ['goals' => ['career']])->step,
        fn (): int => count(app(PlacementTestService::class)->start(Learner::query()->findOrFail($learner->id))->questions),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect($results[0]['result'])->toBe(10)->and($results[2]['result'])->toBe(10)->and($results[1]['result'])->toBe('level');

    $fresh = $learner->fresh();
    expect($fresh?->meta['locale_chosen'])->toBeTrue()
        ->and($fresh?->goals)->toBe(['career'])
        ->and($fresh?->meta['twa_onboarding']['step'])->toBe('level')
        ->and($fresh?->meta['twa_onboarding']['answered'])->toBe(['goals'])
        ->and(plRaceItems($learner->id))->toHaveCount(10);
});

it('scores one set once when three answers of it arrive at the same instant: the same result for all, one answered_at', function (): void {
    $learner = Learner::factory()->create();
    $setId = app(PlacementTestService::class)->start($learner)->setId;
    $items = plRaceItems($learner->id);
    $right = array_column($items, 'correct');
    $wrong = array_map(static fn (array $item): int => ($item['correct'] + 1) % $item['n'], $items);

    $results = plRaceInParallel([
        fn (): array => app(PlacementTestService::class)->answer(Learner::query()->findOrFail($learner->id), $setId, $right)->toArray(),
        fn (): array => app(PlacementTestService::class)->answer(Learner::query()->findOrFail($learner->id), $setId, $wrong)->toArray(),
        fn (): array => app(PlacementTestService::class)->answer(Learner::query()->findOrFail($learner->id), $setId, $right)->toArray(),
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    // Whichever request scored first decided; the others got its result, not their own.
    expect($results[0]['result'])->toBe($results[1]['result'])->toBe($results[2]['result'])
        ->and($results[0]['result']['level'])->toBeIn(['A1', 'C1']);

    $stored = $learner->fresh()?->meta['twa_onboarding']['placement'];
    expect($stored)->toHaveKeys(['set_id', 'answered_at', 'result'])->not->toHaveKey('items')
        ->and($stored['set_id'])->toBe($setId)
        ->and(PlacementResult::fromStored($stored['result'])?->toArray())->toBe($results[0]['result']);
});

it('never scores old answers against a new set when an answer races a new start: the old set is scored, or the answer gets 410', function (): void {
    $learner = Learner::factory()->create();
    $oldSet = app(PlacementTestService::class)->start($learner)->setId;
    $items = plRaceItems($learner->id);
    $right = array_column($items, 'correct');

    $results = plRaceInParallel([
        function () use ($learner, $oldSet, $right): string {
            try {
                return app(PlacementTestService::class)->answer(Learner::query()->findOrFail($learner->id), $oldSet, $right)->level;
            } catch (TwaOnboardingException $e) {
                return 'error:'.$e->httpStatus.':'.$e->errorCode;
            }
        },
        fn (): string => app(PlacementTestService::class)->start(Learner::query()->findOrFail($learner->id))->setId,
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    $newSet = $results[1]['result'];
    expect($newSet)->not->toBe($oldSet);
    // Either the answer came first and scored ITS set (all right → C1), or the start came first and the answer of the replaced set
    // is refused with 410 - never a score of the old answers against the new words.
    expect($results[0]['result'])->toBeIn(['C1', 'error:410:placement_expired']);

    $stored = $learner->fresh()?->meta['twa_onboarding']['placement'];
    expect($stored)->toHaveKey('items')->not->toHaveKey('result')
        ->and($stored['set_id'])->toBe($newSet)
        ->and($stored['items'])->toHaveCount(10);
});

it('refuses with 410 the answers of a set a new start already replaced, while the answer waits on the lock', function (): void {
    $learner = Learner::factory()->create();
    $oldSet = app(PlacementTestService::class)->start($learner)->setId;
    $right = array_column(plRaceItems($learner->id), 'correct');

    // The start holds the row lock first (it sleeps inside it); the answer queues behind it and must see the new set.
    $results = plRaceInParallel([
        function () use ($learner): string {
            return DB::transaction(function () use ($learner): string {
                Learner::query()->whereKey($learner->id)->lock('for no key update')->firstOrFail();
                usleep(300_000);

                return app(PlacementTestService::class)->start(Learner::query()->findOrFail($learner->id))->setId;
            });
        },
        function () use ($learner, $oldSet, $right): string {
            usleep(100_000);
            try {
                return app(PlacementTestService::class)->answer(Learner::query()->findOrFail($learner->id), $oldSet, $right)->level;
            } catch (TwaOnboardingException $e) {
                return 'error:'.$e->httpStatus.':'.$e->errorCode;
            }
        },
    ]);

    foreach ($results as $result) {
        expect($result['ok'])->toBeTrue(($result['error'] ?? '').' '.($result['message'] ?? ''));
    }
    expect($results[1]['result'])->toBe('error:410:placement_expired');
    $stored = $learner->fresh()?->meta['twa_onboarding']['placement'];
    expect($stored)->not->toHaveKey('result')->and($stored['set_id'])->toBe($results[0]['result']);
});
