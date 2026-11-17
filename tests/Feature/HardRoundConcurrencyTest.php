<?php

declare(strict_types=1);

use App\Domain\Learning\Enums\RepetitionState;
use App\Domain\Learning\Exceptions\HardRoundException;
use App\Domain\Learning\Services\HardRoundService;
use App\Domain\Learning\Services\ReviewSubmissionService;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Models\Learner;
use App\Models\TrainingSession;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

/*
 * Real races of the round of hard words (docs/06 §14): several OS processes, each with its own PostgreSQL connection, open it for
 * one learner at the same instant, or open it while a grade of the daily round runs. PostgreSQL only (sqlite is one connection, no
 * row locks) and only where pcntl is available, like TrainingConcurrencyTest; data is committed, hence DatabaseTruncation.
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
    config(['learning.hard_round' => ['enabled' => true, 'words' => 10, 'min_words' => 8, 'per_day' => 1, 'lapses_from' => 2]]);
});

afterEach(function (): void {
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * Each closure runs in a forked child with a fresh connection, all released at the same instant; the JSON-encoded results in task order.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function hardRoundRunConcurrently(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-hard-race-'.bin2hex(random_bytes(4));
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
                // A deadlock or a forgotten lock must show as an error here, not as a hang.
                DB::statement("SET lock_timeout = '4s'");
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

    DB::reconnect();

    return $results;
}

/** @return array{learner: Learner, words: list<int>} a learner with `$hard` hard words (due in five days, graded three days ago) of thirty */
function hardRoundRaceWorld(int $hard = 12): array
{
    ['words' => $ids] = Fx::course([1 => [1 => 30]]);
    $learner = Learner::factory()->create(['timezone' => 'Asia/Tashkent', 'daily_goal' => 10]);
    $words = $ids['1.1']['active'];

    foreach (array_slice($words, 0, $hard) as $wordId) {
        WordRepetition::factory()->create([
            'learner_id' => $learner->id, 'word_id' => $wordId, 'state' => RepetitionState::Review->value,
            'repetitions' => 4, 'interval_days' => 10, 'easiness_factor' => 2.5, 'lapses' => 2, 'is_hard' => false,
            'next_review_at' => now()->addDays(5), 'last_reviewed_at' => now()->subDays(3), 'last_quality' => 4,
            'introduced_mode' => 'daily_review', 'served_ahead_token' => null, 'meta' => [], 'created_at' => now()->subDays(5),
        ]);
    }

    return ['learner' => $learner, 'words' => $words];
}

it('opens exactly one round when several tabs open it at the same instant: one session, one pull', function (): void {
    ['learner' => $learner, 'words' => $words] = hardRoundRaceWorld();
    $learnerId = (int) $learner->id;

    $results = hardRoundRunConcurrently(array_fill(0, 4, function () use ($learnerId): int {
        return (int) app(HardRoundService::class)->open(Learner::query()->findOrFail($learnerId), 'self')->id;
    }));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([]);

    $sessions = TrainingSession::query()->where('learner_id', $learnerId)->get();
    $set = $sessions->sole()->meta['hard_words'] ?? [];

    expect(array_unique(array_column($results, 'result')))->toBe([(int) $sessions->sole()->id])
        ->and($sessions->where('status', 'open'))->toHaveCount(1)
        ->and($set)->toHaveCount(10)
        // One pull: the ten words of the set are due now, the other two of the twelve were not touched.
        ->and(WordRepetition::query()->where('learner_id', $learnerId)->whereIn('word_id', $set)->where('next_review_at', '<=', now()->addSeconds(5))->count())->toBe(10)
        ->and(WordRepetition::query()->where('learner_id', $learnerId)->whereNotIn('word_id', $set)->where('next_review_at', '>', now()->addDay())->count())->toBe(2)
        ->and(array_intersect($set, $words))->toHaveCount(10);
});

it('gives a second tab the day\'s refusal, not a second round, when the first one finished it already', function (): void {
    ['learner' => $learner] = hardRoundRaceWorld();
    $learnerId = (int) $learner->id;
    $first = app(HardRoundService::class)->open($learner, 'self');
    $first->update(['status' => 'closed', 'ended_at' => now(), 'done_reason' => 'finished']);

    $results = hardRoundRunConcurrently(array_fill(0, 3, function () use ($learnerId): string {
        try {
            app(HardRoundService::class)->open(Learner::query()->findOrFail($learnerId), 'self');

            return 'opened';
        } catch (Throwable $e) {
            return $e::class;
        }
    }));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toBe([HardRoundException::class])
        ->and(TrainingSession::query()->where('learner_id', $learnerId)->count())->toBe(1);
});

it('opens the round while a word of its set is graded in the daily round, without a deadlock, over many tries', function (): void {
    ['learner' => $learner, 'words' => $words] = hardRoundRaceWorld(12);
    $learnerId = (int) $learner->id;
    $daily = app(TrainingSessionService::class)->openPersonal($learner, 'daily_review');
    $dailyId = (int) $daily->id;

    // Twelve hard words, six of them already due: the daily round grades them while the round pulls the others.
    $due = array_slice($words, 0, 6);
    WordRepetition::query()->where('learner_id', $learnerId)->whereIn('word_id', $due)->update(['next_review_at' => now()->subHour()]);

    $tasks = [fn (): int => (int) app(HardRoundService::class)->open(Learner::query()->findOrFail($learnerId), 'self')->id];
    foreach ($due as $wordId) {
        $tasks[] = function () use ($learnerId, $dailyId, $wordId): int {
            $learner = Learner::query()->findOrFail($learnerId);
            $session = TrainingSession::query()->findOrFail($dailyId);

            return (int) app(ReviewSubmissionService::class)->submit($session, $learner, $wordId, 4, 500)->word_id;
        };
    }

    $results = hardRoundRunConcurrently($tasks);

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([])
        ->and(TrainingSession::query()->where('learner_id', $learnerId)->where('mode', 'hard_review')->where('status', 'open')->count())->toBe(1)
        ->and(DB::table('training_reviews')->where('training_session_id', $dailyId)->count())->toBe(6);
});
