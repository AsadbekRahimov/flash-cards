<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL for the welcome trial (docs/13 §7.1 "2C.9"): the bot's `/start` and the Mini App's `/auth`
 * of one person at the same instant — two registrations of a new Telegram id, or two first contacts of a passively
 * created row — must leave ONE learner, ONE trial subscription and ONE book row. Each contender is a forked process
 * with its own database connection; needs committed rows: DatabaseTruncation. Skipped on sqlite.
 */

use App\Domain\Billing\Services\ProTrialService;
use App\Domain\Learners\Services\LearnerRegistrar;
use App\Models\Learner;
use App\Models\ProductEvent;
use App\Models\ProTrial;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres) {
    it('races the welcome trial in parallel registrations', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql): real parallel transactions need committed rows and row locks.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config(['billing.trial.days' => 7]);
});

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string, message?: string}>
 */
function trialRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-trial-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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

function expectOneTrial(int $tgId): void
{
    $learner = Learner::query()->where('telegram_user_id', $tgId)->sole();

    expect(Learner::withTrashed()->where('telegram_user_id', $tgId)->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->count())->toBe(1)
        ->and(Subscription::query()->where('subscriber_id', $learner->id)->sole()->provider)->toBe('trial')
        ->and(ProTrial::query()->count())->toBe(1)
        ->and(ProTrial::query()->sole()->telegram_hash)->toBe(ProTrialService::hashFor($tgId))
        ->and($learner->meta)->not->toHaveKey(ProTrialService::META_PENDING)
        ->and(ProductEvent::query()->where('name', 'trial.granted')->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'trial.refused')->count())->toBe(0);
}

it('grants one trial when the bot and the Mini App register the same new Telegram id at the same instant', function (): void {
    $results = trialRunInParallel([
        fn (): int => app(LearnerRegistrar::class)->fromTelegramUser(['id' => 920_001, 'first_name' => 'Ali'])->id,
        fn (): ?int => app(LearnerRegistrar::class)->fromMiniApp(['id' => 920_001, 'first_name' => 'Ali'], time())?->id,
        fn (): int => app(LearnerRegistrar::class)->fromTelegramUser(['id' => 920_001, 'first_name' => 'Ali'])->id,
    ]);

    expect(collect($results)->pluck('ok')->all())->toBe([true, true, true])
        ->and(collect($results)->pluck('result')->unique())->toHaveCount(1);
    expectOneTrial(920_001);
});

it('grants one trial when three first contacts of a passively created learner race', function (): void {
    $passive = app(LearnerRegistrar::class)->observeTelegramUser(['id' => 920_002, 'first_name' => 'Bek'], 'group');
    expect($passive?->meta)->toBe([ProTrialService::META_PENDING => true]);

    $results = trialRunInParallel(array_fill(0, 3, function (): string {
        // Every contender holds a copy of the row that still says "pending".
        app(ProTrialService::class)->offer(Learner::query()->where('telegram_user_id', 920_002)->sole());

        return 'ok';
    }));

    expect(collect($results)->pluck('ok')->all())->toBe([true, true, true]);
    expectOneTrial(920_002);
});
