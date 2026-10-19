<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Learners\Services\DailyStatsService;
use App\Domain\Learning\Services\CardPicker;
use App\Domain\Learning\Services\TrainingSessionService;
use App\Domain\Learning\Support\NewCardBudget;
use App\Domain\Learning\Support\TrainingScope;
use App\Models\Course;
use App\Models\Learner;
use App\Models\LearnerDailyStats;
use App\Models\Lesson;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TrainingSession;
use App\Models\Word;
use App\Models\WordRepetition;
use Carbon\CarbonImmutable;
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

    // Exactly two words were introduced (never three, whatever the interleaving), every tab that got a
    // card got one of those two (a late tab may see an introduced-but-ungraded card as due), and every
    // tab without a card was stopped by the quota — not by an empty lesson.
    expect($rows)->toHaveCount(2)
        ->and(collect($cards)->map(fn (string $c): int => (int) substr($c, 5))->unique()->sort()->values()->all())
        ->toBe($rows->sort()->values()->all())
        ->and($cards)->not->toBeEmpty()
        ->and($others)->each->toBe('done:new_quota_exhausted');
});

it('aggregates six simultaneous grades into one day row, meets the goal once and credits the streak once', function (): void {
    // Six workers grade at the same instant, each in its own review-like transaction (docs/12 §1.6, §7.4).
    // Without the row lock on learner_daily_stats two of them would read the same counters and one grade
    // would be lost; without the "goal_met_at IS NULL" guard the goal would be met more than once.
    $learner = Learner::factory()->create(['daily_goal' => 4, 'timezone' => 'Asia/Tashkent']);
    $learnerId = (int) $learner->id;
    $now = CarbonImmutable::parse('2026-10-18 10:00:00', 'UTC');

    $results = runConcurrently(array_map(
        fn (int $i): Closure => function () use ($learnerId, $now, $i): array {
            $learner = Learner::query()->findOrFail($learnerId);
            $row = DB::transaction(fn (): LearnerDailyStats => app(DailyStatsService::class)->recordReview(
                $learner,
                $i % 2 === 0 ? 5 : 1,        // three correct, three wrong
                100,
                $i < 2,                      // two new cards
                $now,
            ));

            return ['reviews' => $row->reviews_count, 'met' => $row->goal_met_at !== null];
        },
        range(0, 5),
    ));

    expect(array_column(array_filter($results, fn (array $r): bool => ! $r['ok']), 'error'))->toBe([]);

    $rows = LearnerDailyStats::query()->where('learner_id', $learnerId)->get();
    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    expect($row?->date->toDateString())->toBe('2026-10-18')
        ->and($row?->reviews_count)->toBe(6)
        ->and($row?->correct_count)->toBe(3)
        ->and($row?->new_words_count)->toBe(2)
        ->and($row?->time_spent_ms)->toBe(600)
        ->and($row?->goal_met_at)->not->toBeNull();

    // Every worker saw a distinct running total — the increments were serialised, none lost.
    $seen = array_column(array_column($results, 'result'), 'reviews');
    sort($seen);
    expect($seen)->toBe([1, 2, 3, 4, 5, 6]);

    // The goal flipped exactly once (workers with totals 4, 5, 6 see it met), and the streak was credited once.
    $fresh = Learner::query()->findOrFail($learnerId);
    expect($fresh->streak_current)->toBe(1)
        ->and($fresh->streak_best)->toBe(1)
        ->and($fresh->streak_last_date?->toDateString())->toBe('2026-10-18')
        ->and(ProductEvent::query()->where('name', ProductEventName::DailyGoalMet->value)->where('learner_id', $learnerId)->count())->toBe(1);
});
