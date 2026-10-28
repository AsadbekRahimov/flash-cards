<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL (docs/13 "Реализация P1-1"): forked processes with their own connections
 * write the same `assignment_results` rows at the same instant — several GETs of one learner, a
 * learner's GET against the group refresh of the same assignments, and the refresh job, the report
 * opening and the close of one assignment. The upsert by `(assignment_id, learner_id)` must never
 * fail on the unique key and the conditional UPDATE of `completed_at` must hand every completion
 * (and its `assignment.completed` event) to exactly one writer. Needs committed rows, hence
 * DatabaseTruncation; sqlite `:memory:` is one connection without row locks — skipped there.
 */

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Organizations\Services\AssignmentService;
use App\Models\Assignment;
use App\Models\AssignmentResult;
use App\Models\Course;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\ProductEvent;
use App\Models\Stage;
use App\Models\TelegramGroup;
use App\Models\Word;
use App\Models\WordRepetition;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('claims every assignment completion exactly once across parallel writers', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel connections and row-level UPDATE semantics.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * Runs every task in its own forked process (own DB connection), all released at the same
 * instant; returns what each returned or threw.
 *
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function assignmentsRunInParallel(array $tasks): array
{
    // The children must not inherit (and later close) the parent's socket.
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-race-'.getmypid().'-'.bin2hex(random_bytes(4));
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

/** A center, a group of its course and `$lessons` lessons of four active words; returns [group, list<Lesson>]. */
function raceAssignmentsFixture(int $lessons): array
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $org = Organization::factory()->create();
    $group = TelegramGroup::factory()->create(['organization_id' => $org->id, 'course_id' => $course->id]);

    $made = [];
    foreach (range(1, $lessons) as $i) {
        $lesson = Lesson::factory()->create(['stage_id' => $stage->id, 'number' => $i]);
        Word::factory()->count(4)->for($lesson)->create(['is_active' => true]);
        $made[] = $lesson;
    }

    return [$group, $made];
}

function raceAssign(TelegramGroup $group, Lesson $lesson): Assignment
{
    return Assignment::factory()->create([
        'organization_id' => $group->organization_id,
        'telegram_group_id' => $group->id,
        'lesson_id' => $lesson->id,
        'due_at' => now()->addDays(3),
    ]);
}

/** The learner has repeated every active word of the lesson. */
function raceLearnAll(Learner $learner, Lesson $lesson): void
{
    foreach (Word::query()->where('lesson_id', $lesson->id)->pluck('id') as $wordId) {
        WordRepetition::factory()->create(['learner_id' => $learner->id, 'word_id' => $wordId, 'repetitions' => 1, 'last_reviewed_at' => now()]);
    }
}

/** @return array<string, int> "assignment_id:learner_id" => number of `assignment.completed` events */
function raceCompletedEvents(): array
{
    $counts = [];
    foreach (ProductEvent::query()->where('name', ProductEventName::AssignmentCompleted->value)->get() as $event) {
        $key = ($event->props['assignment_id'] ?? 'none').':'.($event->learner_id ?? 'none');
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

it('claims every assignment completion exactly once across parallel writers', function (): void {
    // Several GETs of one learner who just reached the threshold on five assignments.
    [$group, $lessons] = raceAssignmentsFixture(5);
    $learner = Learner::factory()->memberOf($group)->create();
    $assignments = array_map(static fn (Lesson $lesson): Assignment => raceAssign($group, $lesson), $lessons);
    foreach ($lessons as $lesson) {
        raceLearnAll($learner, $lesson);
    }
    $learnerId = (int) $learner->id;

    $results = assignmentsRunInParallel(array_fill(0, 4, static function () use ($learnerId): array {
        $items = app(AssignmentService::class)->forLearner(Learner::query()->findOrFail($learnerId));

        return [
            'count' => count($items),
            'completed' => count(array_filter($items, static fn ($item): bool => $item->completed())),
        ];
    }));

    $errors = array_column(array_filter($results, static fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    // Every GET saw all five as completed (the re-read after the writes), whichever one claimed them.
    foreach ($results as $r) {
        expect($r['result'])->toBe(['count' => 5, 'completed' => 5]);
    }

    $events = raceCompletedEvents();
    expect(array_sum($events))->toBe(5)
        ->and(array_keys($events))->toHaveCount(5)
        ->and(AssignmentResult::query()->whereNotNull('completed_at')->count())->toBe(5)
        ->and(AssignmentResult::query()->count())->toBe(5);
    foreach ($assignments as $assignment) {
        expect($events[$assignment->id.':'.$learnerId] ?? 0)->toBe(1);
    }
});

it('raises one completion per (assignment, learner) when a learner\'s GET races the group refresh', function (): void {
    [$group, $lessons] = raceAssignmentsFixture(3);
    $learners = Learner::factory()->count(4)->memberOf($group)->create();
    $assignments = array_map(static fn (Lesson $lesson): Assignment => raceAssign($group, $lesson), $lessons);
    foreach ($learners as $learner) {
        foreach ($lessons as $lesson) {
            raceLearnAll($learner, $lesson);
        }
    }
    $first = (int) $learners[0]->id;
    $second = (int) $learners[1]->id;
    $assignmentIds = array_map(static fn (Assignment $a): int => (int) $a->id, $assignments);

    $tasks = [
        static fn (): int => count(app(AssignmentService::class)->forLearner(Learner::query()->findOrFail($first))),
        static fn (): int => count(app(AssignmentService::class)->forLearner(Learner::query()->findOrFail($first))),
        static fn (): int => count(app(AssignmentService::class)->forLearner(Learner::query()->findOrFail($second))),
    ];
    foreach ($assignmentIds as $id) {
        $tasks[] = static fn (): int => app(AssignmentService::class)->refreshResults(Assignment::query()->findOrFail($id));
    }

    $results = assignmentsRunInParallel($tasks);

    $errors = array_column(array_filter($results, static fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    // 3 assignments × 4 learners: one row, one claim, one event each — no matter who wrote first.
    $events = raceCompletedEvents();
    expect(array_sum($events))->toBe(12)
        ->and(array_keys($events))->toHaveCount(12)
        ->and(max($events))->toBe(1)
        ->and(AssignmentResult::query()->count())->toBe(12)
        ->and(AssignmentResult::query()->whereNull('completed_at')->count())->toBe(0)
        ->and(AssignmentResult::query()->where('progress_pct', '<', 100)->count())->toBe(0);

    // The refreshes together reported exactly the completions the GETs did not take.
    $byRefresh = array_sum(array_map(static fn (array $r): int => (int) $r['result'], array_slice($results, 3)));
    expect($byRefresh)->toBeLessThanOrEqual(12)->toBeGreaterThanOrEqual(12 - 2 * 3 - 3);
});

it('lets the refresh job, the report opening and the close race on one assignment and claims each learner once', function (): void {
    [$group, $lessons] = raceAssignmentsFixture(1);
    $learners = Learner::factory()->count(30)->memberOf($group)->create();
    $assignment = raceAssign($group, $lessons[0]);
    foreach ($learners as $learner) {
        raceLearnAll($learner, $lessons[0]);
    }
    $assignmentId = (int) $assignment->id;

    $results = assignmentsRunInParallel([
        static fn (): int => app(AssignmentService::class)->refreshResults(Assignment::query()->findOrFail($assignmentId)),
        static fn (): bool => app(AssignmentService::class)->refreshIfStale(Assignment::query()->findOrFail($assignmentId)),
        static fn (): int => app(AssignmentService::class)->refreshActive(),
        static fn (): bool => app(AssignmentService::class)->close(Assignment::query()->findOrFail($assignmentId)),
    ]);

    $errors = array_column(array_filter($results, static fn (array $r): bool => ! $r['ok']), 'error');
    expect($errors)->toBe([]);

    $events = raceCompletedEvents();
    expect(array_sum($events))->toBe(30)
        ->and(array_keys($events))->toHaveCount(30)
        ->and(AssignmentResult::query()->where('assignment_id', $assignmentId)->whereNotNull('completed_at')->count())->toBe(30)
        ->and(AssignmentResult::query()->count())->toBe(30)
        ->and(Assignment::query()->findOrFail($assignmentId)->status)->toBe(Assignment::STATUS_CLOSED)
        ->and($results[3]['result'])->toBeTrue();

    // A later run finds nothing to claim.
    expect(app(AssignmentService::class)->refreshResults(Assignment::query()->findOrFail($assignmentId)))->toBe(0)
        ->and(array_sum(raceCompletedEvents()))->toBe(30);
});
