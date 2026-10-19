<?php

declare(strict_types=1);

use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/*
 * Real races: several OS processes, each with its own PostgreSQL connection, hit the
 * same learner at the same instant. Row locks (`FOR UPDATE` on `learners`) and the
 * partial unique indexes on `training_sessions` are what the assertions rely on, so
 * this file runs only on pgsql (sqlite :memory: is one connection, no row locks) and
 * only where pcntl is available. Data is committed (no wrapping transaction), hence
 * DatabaseTruncation plus an explicit truncate after each test.
 */

uses(DatabaseTruncation::class);

beforeEach(function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL: real parallel connections and row locks.');
    }
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions to fork workers.');
    }
});

afterEach(function (): void {
    if (config('database.default') === 'pgsql') {
        DB::reconnect();
        $this->truncateTablesForAllConnections();
    }
});

/**
 * Runs each closure in a forked child with a fresh DB connection, all released at
 * the same instant; returns the JSON-encoded results in task order.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function runConcurrently(array $tasks): array
{
    // The parent must not share its socket with the children: close it before forking.
    DB::disconnect();

    $dir = sys_get_temp_dir().'/lexiflow-race-'.bin2hex(random_bytes(4));
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

function raceLesson(int $words): Lesson
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->create(['stage_id' => $stage->id]);
    Word::factory()->count($words)->for($lesson)->create();

    return $lesson;
}

it('opens exactly one daily review when several tabs open it at the same instant', function (): void {
    $learner = Learner::factory()->create();
    $learnerId = (int) $learner->id;

    $results = runConcurrently(array_fill(0, 4, function () use ($learnerId): int {
        $learner = Learner::query()->findOrFail($learnerId);

        return (int) app(TrainingSessionService::class)->openPersonal($learner, 'daily_review')->id;
    }));

    $errors = array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $ids = array_unique(array_column($results, 'result'));
    expect($ids)->toHaveCount(1)
        ->and(TrainingSession::query()->where('learner_id', $learnerId)->count())->toBe(1)
        ->and(TrainingSession::query()->where('learner_id', $learnerId)->where('status', 'open')->count())->toBe(1);
});

it('opens exactly one lesson session per (learner, lesson) under concurrent opens', function (): void {
    $lesson = raceLesson(1);
    $learner = Learner::factory()->create();
    $learnerId = (int) $learner->id;
    $lessonId = (int) $lesson->id;

    $results = runConcurrently(array_fill(0, 3, function () use ($learnerId, $lessonId): int {
        return (int) app(TrainingSessionService::class)->openPersonal(
            Learner::query()->findOrFail($learnerId),
            'lesson',
            Lesson::query()->findOrFail($lessonId),
        )->id;
    }));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([])
        ->and(array_unique(array_column($results, 'result')))->toHaveCount(1)
        ->and(TrainingSession::query()->where('learner_id', $learnerId)->count())->toBe(1);
});

it('introduces each new word once when four tabs of one learner pick at the same instant', function (): void {
    $lesson = raceLesson(4);
    $learner = Learner::factory()->create();
    $learnerId = (int) $learner->id;
    $lessonId = (int) $lesson->id;

    $results = runConcurrently(array_fill(0, 4, function () use ($learnerId, $lessonId): ?int {
        $pick = app(CardPicker::class)->pickNext($learnerId, TrainingScope::lesson($lessonId));

        return $pick->word?->id === null ? null : (int) $pick->word->id;
    }));

    // No tab crashed (without the learner lock two tabs INSERT the same word → unique violation).
    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([]);

    $served = array_column($results, 'result');
    $rows = WordRepetition::query()->where('learner_id', $learnerId)->get();

    // Every tab got a card. A tab that started after another one committed may legitimately receive
    // that tab's still-ungraded `new` card through the due step (docs/06 §4.2) — so the served ids
    // need not all differ, but every word was introduced exactly once: one row per distinct word,
    // and no word was introduced without being served.
    expect($served)->each->not->toBeNull()
        ->and($rows->pluck('word_id')->sort()->values()->all())->toBe(collect($served)->unique()->sort()->values()->all())
        ->and($rows->count())->toBeLessThanOrEqual(4)
        ->and($rows->pluck('word_id')->unique())->toHaveCount($rows->count());
});

it('does not let four tabs jointly exceed a budget of two new cards', function (): void {
    $lesson = raceLesson(5);
    $learner = Learner::factory()->create();
    $learnerId = (int) $learner->id;
    $lessonId = (int) $lesson->id;

    // A quota resolver in the spirit of task 1.4b: 2 new cards per day, counted under the learner lock.
    $budget = fn (): NewCardBudget => NewCardBudget::lazy(
        fn (int $id): int => 2 - WordRepetition::query()->where('learner_id', $id)->count(),
    );

    $results = runConcurrently(array_fill(0, 4, function () use ($learnerId, $lessonId, $budget): string {
        $pick = app(CardPicker::class)->pickNext($learnerId, TrainingScope::lesson($lessonId), $budget());

        return $pick->hasCard() ? 'card:'.$pick->word?->id : 'done:'.$pick->doneReason?->value;
    }));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([]);

    $outcomes = array_column($results, 'result');
    $cards = array_values(array_filter($outcomes, fn (string $o): bool => str_starts_with($o, 'card:')));
    $others = array_values(array_filter($outcomes, fn (string $o): bool => ! str_starts_with($o, 'card:')));
    $rows = WordRepetition::query()->where('learner_id', $learnerId)->pluck('word_id');

    // Never more than two words were introduced, whatever the interleaving. A tab that starts after
    // another one committed may legitimately receive that tab's still-ungraded `new` card through the
    // due step (docs/06 §4.2) and never reach the quota check, so as few as one row is possible; but
    // a tab can only be stopped by the quota once the budget is really spent: then there are exactly
    // two rows. Every card served is one of the introduced words, every tab without a card was
    // stopped by the quota — not by an empty lesson.
    expect($rows->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)
        ->and($cards)->not->toBeEmpty()
        ->and(collect($cards)->map(fn (string $c): int => (int) substr($c, 5))->unique()->diff($rows)->all())->toBe([])
        ->and($others)->each->toBe('done:new_quota_exhausted');

    if ($others !== []) {
        expect($rows)->toHaveCount(2);
    }
});
