<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL (task 2D.4): several processes open the learner's mock at the same
 * instant -> one session (partial unique `exam_sessions_one_open_mock_per_learner` + the loser
 * re-reading the winner); several finalize the same mock -> one result row, one event.
 * Mirrors TrainingConcurrencyTest; pgsql + pcntl only.
 */

use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Learning\Services\LeaderboardBuilder;
use App\Domain\Learning\Services\MockExamService;
use App\Models\Course;
use App\Models\ExamAnswer;
use App\Models\ExamResult;
use App\Models\ExamSession;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\Subscription;
use App\Models\TelegramGroup;
use App\Models\Word;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
    Cache::flush();
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
function mockRaceRun(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-mock-race-'.bin2hex(random_bytes(4));
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

/** @return array{learner: Learner, course: Course} */
function mockRaceFixture(): array
{
    $learner = Learner::factory()->onboarded()->create();
    Subscription::factory()->create(['subscriber_id' => $learner->id]);
    app(EntitlementResolver::class)->forget($learner);

    $course = Course::factory()->published()->create(['exam_tag' => 'ielts']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create();
    foreach (range(1, 12) as $i) {
        Word::factory()->for($lesson)->create(['word' => "race{$i}", 'translation' => "гонка {$i}"]);
    }

    return ['learner' => $learner, 'course' => $course];
}

it('opens exactly one mock when several tabs open it at the same instant', function (): void {
    ['learner' => $learner, 'course' => $course] = mockRaceFixture();
    $learnerId = (int) $learner->id;
    $courseId = (int) $course->id;

    $results = mockRaceRun(array_fill(0, 4, function () use ($learnerId, $courseId): int {
        return (int) app(MockExamService::class)->open(
            Learner::query()->findOrFail($learnerId),
            Course::query()->findOrFail($courseId),
        )->id;
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    expect(array_unique(array_column($results, 'result')))->toHaveCount(1)
        ->and(ExamSession::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(ProductEvent::query()->where('name', 'exam.mock_started')->count())->toBe(1);
});

it('finalizes a mock once when several requests race for it', function (): void {
    ['learner' => $learner, 'course' => $course] = mockRaceFixture();
    $session = app(MockExamService::class)->open($learner, $course);
    $sessionId = (int) $session->id;

    $results = mockRaceRun(array_fill(0, 4, function () use ($sessionId): string {
        return app(MockExamService::class)
            ->finalize(ExamSession::query()->findOrFail($sessionId), 'expired')
            ->status;
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toBe(['closed'])
        ->and(ExamResult::query()->where('exam_session_id', $sessionId)->count())->toBe(0)
        ->and(ProductEvent::query()->where('name', 'exam.mock_finished')->count())->toBe(1);
});

it('rebuilds the leaderboard without duplicates when several builds race for one exam', function (): void {
    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $lesson = Lesson::factory()->for(Stage::factory()->create())->create();
    $session = ExamSession::factory()->withQuestions(4)->create([
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'status' => 'closed',
        'started_at' => now()->subMinutes(5),
        'ends_at' => now()->subMinute(),
    ]);
    $word = Word::factory()->for($lesson)->create();
    $sessionId = (int) $session->id;

    foreach (range(1, 5) as $i) {
        $learner = Learner::factory()->memberOf($group)->create();
        ExamAnswer::query()->insert([
            'exam_session_id' => $sessionId, 'learner_id' => $learner->id, 'word_id' => $word->id,
            'selected_translation' => 'x', 'is_correct' => true, 'score' => 10 * $i,
            'time_spent_ms' => 1000, 'answered_at' => now(),
        ]);
    }

    $results = mockRaceRun(array_fill(0, 4, function () use ($sessionId): int {
        return app(LeaderboardBuilder::class)->build(ExamSession::query()->findOrFail($sessionId))->count();
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toBe([5])
        ->and(ExamResult::query()->where('exam_session_id', $sessionId)->count())->toBe(5)
        ->and(ExamResult::query()->where('exam_session_id', $sessionId)->pluck('rank')->sort()->values()->all())->toBe([1, 2, 3, 4, 5]);
});
