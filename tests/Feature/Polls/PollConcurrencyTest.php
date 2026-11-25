<?php

declare(strict_types=1);

/*
 * docs/35 O3/O4, real races: forked processes, each with its own PostgreSQL connection, released at the same instant.
 *  - several workers broadcast one poll: every learner gets exactly one message, also when the leases run out mid-batch and the
 *    workers fight over the same rows (only the conditional step to `sending` decides);
 *  - a stop while the workers send: at most the message each worker had started goes out, nothing pending stays;
 *  - the same poll launched by several administrators at once: one launch, one row per learner;
 *  - simultaneous taps of one learner: one answer and agreeing counters (no change allowed / changes allowed / toggles of
 *    several answers - no lost update of the draft).
 * Needs committed rows (DatabaseTruncation), PostgreSQL and pcntl/posix.
 */

use App\Domain\Polls\Enums\PollDeliveryStatus;
use App\Domain\Polls\Enums\PollStatus;
use App\Domain\Polls\Enums\PollTapStatus;
use App\Domain\Polls\Services\PollDeliverySender;
use App\Domain\Polls\Services\PollLauncher;
use App\Domain\Polls\Services\PollVoteService;
use App\Domain\Telegram\Contracts\TelegramClient;
use App\Models\Poll;
use App\Models\PollDelivery;
use App\Models\PollVote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeTelegramClient;
use Tests\Support\ForkSafe;
use Tests\Support\Polls\PollFixtures;

$pollsOnPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $pollsOnPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('keeps every poll delivery and vote unique under real parallel processes', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel processes.');

    return;
}

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    config(['polls.send_per_second' => 0, 'telegram.outbound.sync_wait_ms' => 0, 'polls.batch_size' => 10, 'polls.lease_seconds' => 300]);
    $this->tg = new FakeTelegramClient;
    app()->instance(TelegramClient::class, $this->tg);
});

afterEach(function (): void {
    ForkSafe::releaseConnections();
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function pollRunInParallel(array $tasks, float $startIn = 0.5): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-poll-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $startAt = microtime(true) + $startIn;
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
                    usleep(200);
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
        $results[$i] = $raw === false ? ['ok' => false, 'error' => 'worker died without a result'] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        @unlink("{$dir}/{$i}.json");
    }
    @rmdir($dir);

    ForkSafe::releaseConnections();
    DB::reconnect();

    return $results;
}

/** A worker: batches until its chain ends; returns the chats it sent to. */
function pollWorker(int $pollId, FakeTelegramClient $tg, int $sendMicros): Closure
{
    return function () use ($pollId, $tg, $sendMicros): array {
        $tg->beforeSend = static function () use ($sendMicros): void {
            usleep($sendMicros);
        };
        $sender = app(PollDeliverySender::class);
        for ($guard = 0; $guard < 500; $guard++) {
            $outcome = $sender->sendBatch($pollId, 60);
            if (! $outcome->more) {
                break;
            }
        }

        return array_column($tg->textMessages, 'chat_id');
    };
}

it('sends every learner exactly one message when four workers broadcast one poll', function (): void {
    $poll = PollFixtures::launched(60);

    $results = pollRunInParallel(array_fill(0, 4, pollWorker($poll->id, $this->tg, 2000)));

    $chats = [];
    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
        $chats = [...$chats, ...$r['result']];
    }

    expect($chats)->toHaveCount(60)
        ->and(array_unique($chats))->toHaveCount(60)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->where('status', 'sent')->count())->toBe(60)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sent)
        // Every worker had a share: the leases spread the rows.
        ->and(count(array_filter($results, fn (array $r): bool => $r['result'] !== [])))->toBeGreaterThan(1);
});

it('sends each row once when the leases run out mid-batch and the workers fight over the same rows', function (): void {
    config(['polls.batch_size' => 40, 'polls.lease_seconds' => 1]);
    $poll = PollFixtures::launched(40);

    // 40 rows × 60 ms ≈ 2.4 s per batch: the 1-second lease runs out under every worker, the others lease the same rows again.
    $results = pollRunInParallel(array_fill(0, 3, pollWorker($poll->id, $this->tg, 60_000)));

    $chats = [];
    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
        $chats = [...$chats, ...$r['result']];
    }

    expect($chats)->toHaveCount(40)
        ->and(array_unique($chats))->toHaveCount(40)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->where('status', 'sent')->count())->toBe(40);
});

it('stops a broadcast while two workers send: at most the started messages go out, nothing stays pending', function (): void {
    config(['polls.batch_size' => 5]);
    $poll = PollFixtures::launched(40);
    $admin = PollFixtures::admin(null);

    $stopper = function () use ($poll, $admin): int {
        usleep(400_000);
        app(PollLauncher::class)->cancel(Poll::query()->findOrFail($poll->id), $admin);

        return PollDelivery::query()->where('poll_id', $poll->id)->whereIn('status', ['sent', 'sending'])->count();
    };

    $results = pollRunInParallel([pollWorker($poll->id, $this->tg, 30_000), pollWorker($poll->id, $this->tg, 30_000), $stopper]);

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }
    $sentAtStop = $results[2]['result'];
    $sent = count($results[0]['result']) + count($results[1]['result']);
    $rows = PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id');

    expect($poll->fresh()->status)->toBe(PollStatus::Cancelled)
        ->and($sent)->toBeGreaterThan(0)
        ->and($sent)->toBeLessThan(40)
        // Each of the two workers may finish the one message it had started when the stop committed.
        ->and($sent)->toBeLessThanOrEqual($sentAtStop + 2)
        ->and((clone $rows)->where('status', 'sent')->count())->toBe($sent)
        ->and((clone $rows)->whereIn('status', ['pending', 'sending'])->count())->toBe(0)
        ->and((clone $rows)->where('status', 'skipped')->count())->toBe(40 - $sent);
});

it('launches a poll once when several administrators launch it at the same time', function (): void {
    PollFixtures::learners(30);
    $poll = PollFixtures::poll();
    PollFixtures::tested($poll);
    $admins = [PollFixtures::admin(null), PollFixtures::admin(null), PollFixtures::admin(null), PollFixtures::admin(null)];
    Queue::fake();

    $results = pollRunInParallel(array_map(
        fn ($admin): Closure => fn (): bool => app(PollLauncher::class)->launch(Poll::query()->findOrFail($poll->id), $admin),
        $admins,
    ));

    expect(array_column($results, 'ok'))->toBe([true, true, true, true])
        ->and(count(array_filter(array_column($results, 'result'))))->toBe(1)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->count())->toBe(30)
        ->and(PollDelivery::query()->where('poll_id', $poll->id)->whereNotNull('learner_id')->distinct()->count('learner_id'))->toBe(30)
        ->and($poll->fresh()->recipient_count)->toBe(30)
        ->and($poll->fresh()->status)->toBe(PollStatus::Sending);
});

it('records one answer for simultaneous taps when the answer cannot be changed', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => false], [], 6);
    $tasks = [];
    foreach ([0, 1, 2, 3, 4, 5] as $n) {
        $tasks[] = fn (): string => app(PollVoteService::class)->tap($delivery->token, $delivery->telegram_user_id, (string) $n, CarbonImmutable::now())->status->value;
    }

    $results = pollRunInParallel($tasks);

    $verdicts = array_column($results, 'result');
    expect(array_column($results, 'ok'))->toBe(array_fill(0, 6, true))
        ->and(array_count_values($verdicts)[PollTapStatus::Voted->value] ?? 0)->toBe(1)
        ->and(PollVote::query()->where('poll_delivery_id', $delivery->id)->count())->toBe(1)
        ->and($delivery->fresh()->changed_count)->toBe(0)
        ->and($delivery->fresh()->answered_at)->not->toBeNull();
});

it('keeps one vote and an exact changed_count for simultaneous different taps when changes are allowed', function (): void {
    $delivery = PollFixtures::delivered(['allow_change' => true], [], 6);
    $tasks = [];
    foreach ([0, 1, 2, 3, 4, 5] as $n) {
        $tasks[] = function () use ($delivery, $n): array {
            $r = app(PollVoteService::class)->tap($delivery->token, $delivery->telegram_user_id, (string) $n, CarbonImmutable::now());

            return ['status' => $r->status->value, 'changed' => $r->changed];
        };
    }

    $results = pollRunInParallel($tasks);

    $changed = count(array_filter(array_column(array_column($results, 'result'), 'changed')));
    expect(array_unique(array_column(array_column($results, 'result'), 'status')))->toBe([PollTapStatus::Voted->value])
        ->and(PollVote::query()->where('poll_delivery_id', $delivery->id)->count())->toBe(1)
        ->and($changed)->toBe(5)
        ->and($delivery->fresh()->changed_count)->toBe(5);
});

it('loses no tick when several options of a multiple-answer poll are toggled at the same time', function (): void {
    $delivery = PollFixtures::delivered(['allows_multiple' => true, 'max_choices' => null], [], 5);
    $tasks = [];
    foreach ([0, 1, 2, 3, 4] as $n) {
        $tasks[] = fn (): string => app(PollVoteService::class)->tap($delivery->token, $delivery->telegram_user_id, (string) $n, CarbonImmutable::now())->status->value;
    }

    $results = pollRunInParallel($tasks);

    expect(array_unique(array_column($results, 'result')))->toBe([PollTapStatus::Toggled->value])
        ->and($delivery->fresh()->draft)->toHaveCount(5);
});
