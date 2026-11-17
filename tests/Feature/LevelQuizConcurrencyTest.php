<?php

declare(strict_types=1);

use App\Domain\Learning\Services\LevelQuizService;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\LevelQuizResult;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

/*
 * Real races on PostgreSQL (docs/13 §8.5): two tabs open the quiz at the same instant, two finalizations of one quiz meet. The partial
 * unique index `exam_sessions_one_open_quiz_per_learner` and the row lock of the session are what the assertions rely on. Runs only
 * on pgsql with pcntl (see TrainingConcurrencyTest for why the trait is attached conditionally).
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
    config(['readiness.enabled' => true, 'readiness.quiz.enabled' => true]);
});

afterEach(function (): void {
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function levelQuizRace(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-quiz-race-'.bin2hex(random_bytes(4));
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

    DB::reconnect();

    return $results;
}

it('opens ONE quiz when several tabs open it at the same instant', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 20]]);
    $learnerId = (int) Learner::factory()->onboarded()->create()->id;
    $courseId = (int) $course->id;

    $results = levelQuizRace(array_fill(0, 4, fn (): int => (int) app(LevelQuizService::class)->open(
        Learner::query()->findOrFail($learnerId),
        Course::query()->findOrFail($courseId),
    )->id));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toHaveCount(1)
        ->and(ExamSession::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'level_quiz.started')->count())->toBe(1);
});

it('finalizes a quiz ONCE when several processes close it at the same instant: one result row, one event', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 20]]);
    $learner = Learner::factory()->onboarded()->create();
    $session = app(LevelQuizService::class)->open($learner, $course);
    foreach (array_slice($session->config['questions'], 0, 3) as $i => $q) {
        ExamAnswer::query()->create([
            'exam_session_id' => $session->id, 'learner_id' => $learner->id, 'word_id' => $q['word_id'],
            'selected_translation' => 'x', 'is_correct' => $i < 2, 'score' => 0, 'time_spent_ms' => 1000, 'answered_at' => now(),
        ]);
    }
    $sessionId = (int) $session->id;
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());

    $results = levelQuizRace(array_fill(0, 4, fn (): string => app(LevelQuizService::class)
        ->finalize(ExamSession::query()->findOrFail($sessionId), 'expired')->status));
    CarbonImmutable::setTestNow();

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toBe(['closed'])
        ->and(LevelQuizResult::query()->where('exam_session_id', $sessionId)->count())->toBe(1)
        ->and(LevelQuizResult::query()->where('exam_session_id', $sessionId)->sole()->correct)->toBe(2)
        ->and(ProductEvent::query()->where('name', 'level_quiz.finished')->count())->toBe(1);
});
