<?php

declare(strict_types=1);

/*
 * Real races on PostgreSQL between the writers of ONE course (docs/03 §6: `courses.words_count` is the source of
 * the catalog totals): every contender is a forked process with its own connection, released at the same instant.
 * The importer locks the course row first in its transaction (as the decks and the publication do), so parallel
 * imports into one course take turns: the counter ends at the real number of active words, a file submitted twice
 * at once adds its words once instead of failing on their unique key, and no order of the writers forms a cycle.
 * Needs committed rows (children cannot see the parent's open transaction): DatabaseTruncation, not
 * RefreshDatabase. Skipped on sqlite.
 */

use App\Domain\Content\Services\CoursePublisher;
use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;
use Tests\Support\HotPathFixtures as Fx;

$onPostgres = (getenv('DB_CONNECTION') ?: 'sqlite') === 'pgsql';

if (! $onPostgres || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
    it('keeps courses.words_count right when two imports into one course run at once', function (): void {})
        ->skip('Requires PostgreSQL (DB_CONNECTION=pgsql) and pcntl/posix: real parallel transactions and row locks.');

    return;
}

uses(DatabaseTruncation::class);

afterEach(function (): void {
    DB::reconnect();
    $this->truncateTablesForAllConnections();
});

/**
 * @param  list<callable(): mixed>  $tasks
 * @return list<array{ok: bool, result?: mixed, error?: string}>
 */
function importRunInParallel(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-import-race-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $startAt = microtime(true) + 0.5;
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

/**
 * Makes the race real: the audit row is the last write of both the import and the publication, so every
 * transaction keeps what it holds for a while before it commits. Without the course lock two imports are
 * then both past their `recount` when the first one commits.
 */
function importHoldBeforeCommit(int $microseconds = 300_000): void
{
    AuditLog::created(static function () use ($microseconds): void {
        usleep($microseconds);
    });
}

/**
 * A file of `$words` new words for lesson `$lesson` of stage `$stage` of the course. The titles are the given ones
 * (a file that repeats the titles of an existing stage / lesson rewrites neither row).
 */
function importFile(Course $course, int $stage, int $lesson, int $words, ?string $stageTitle = null, ?string $lessonTitle = null): string
{
    return json_encode([
        'course' => $course->slug,
        'stage' => ['number' => $stage, 'title' => $stageTitle ?? "Stage {$stage}"],
        'lesson' => ['number' => $lesson, 'title' => $lessonTitle ?? "Lesson {$lesson}"],
        'words' => array_map(
            static fn (int $i): array => ['word' => "s{$stage}l{$lesson}w{$i}", 'translation' => "п{$stage}-{$lesson}-{$i}"],
            range(1, $words),
        ),
    ], JSON_THROW_ON_ERROR);
}

/** @return array{ok: bool, added: int} */
function importRun(string $json): array
{
    $report = app(VocabularyImporter::class)->importAsSystem($json);

    return ['ok' => $report->ok(), 'added' => $report->added];
}

it('keeps courses.words_count right when two imports into one course run at once', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 2]]);
    expect($course->words_count)->toBe(2);

    $results = importRunInParallel([
        function () use ($course): array {
            importHoldBeforeCommit();

            return importRun(importFile($course, 1, 2, 3));
        },
        function () use ($course): array {
            importHoldBeforeCommit();

            return importRun(importFile($course, 1, 3, 4));
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result.ok')->unique()->all())->toBe([true])
        ->and(collect($results)->sum('result.added'))->toBe(7)
        // 2 + 3 + 4: neither import's words are lost from the counter whichever commits last.
        ->and(Word::query()->where('is_active', true)->count())->toBe(9)
        ->and($course->fresh()?->words_count)->toBe(9);
});

it('keeps courses.words_count right when five imports of one course run at once, into old and new stages', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 1]]);

    $tasks = [];
    foreach ([[1, 2, 2], [1, 3, 3], [1, 4, 1], [2, 1, 4], [2, 2, 3]] as [$stage, $lesson, $words]) {
        $tasks[] = function () use ($course, $stage, $lesson, $words): array {
            importHoldBeforeCommit(150_000);

            return importRun(importFile($course, $stage, $lesson, $words));
        };
    }

    $results = importRunInParallel($tasks);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(Word::query()->where('is_active', true)->count())->toBe(1 + 2 + 3 + 1 + 4 + 3)
        ->and($course->fresh()?->words_count)->toBe(14)
        ->and(Lesson::query()->count())->toBe(6)
        ->and(Stage::query()->where('course_id', $course->id)->count())->toBe(2);
});

it('adds the new words of a file submitted twice at once to an existing lesson only once, and fails neither of the two', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 2]]);
    // Lesson 1 exists and the file repeats its titles, so no stage / lesson row is rewritten (a rewrite would
    // already make the second import wait for the first): three words are new to the lesson.
    $file = importFile($course, 1, 1, 3, Stage::query()->sole()->title, Lesson::query()->sole()->title ?? '');

    $results = importRunInParallel(array_fill(0, 2, function () use ($file): array {
        importHoldBeforeCommit();

        return importRun($file);
    }));

    // Without the lock the second import does not see the words of the first (uncommitted), inserts them
    // again and dies on the unique (lesson_id, word); with it, it waits for the commit and skips them.
    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('result.ok')->unique()->all())->toBe([true])
        ->and(collect($results)->pluck('result.added')->sort()->values()->all())->toBe([0, 3])
        ->and(Word::query()->where('is_active', true)->count())->toBe(5)
        ->and($course->fresh()?->words_count)->toBe(5);
});

it('does not deadlock imports of a course with its publication moves, whichever takes the course first', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    ['course' => $course] = Fx::course([1 => [1 => 2]]);

    // Archived and re-opened while the imports run: every transition locks the course row for its whole transaction.
    $results = importRunInParallel([
        function () use ($course): array {
            importHoldBeforeCommit();

            return importRun(importFile($course, 1, 2, 3));
        },
        function () use ($course, $admin): string {
            importHoldBeforeCommit();
            $publisher = app(CoursePublisher::class);
            $publisher->transition(Course::query()->findOrFail($course->id), CoursePublisher::ARCHIVED, User::query()->findOrFail($admin->id));

            return Course::query()->findOrFail($course->id)->status;
        },
        function () use ($course): array {
            importHoldBeforeCommit();

            return importRun(importFile($course, 1, 3, 4));
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[1]['result'])->toBe(CoursePublisher::ARCHIVED)
        ->and($course->fresh()?->status)->toBe(CoursePublisher::ARCHIVED)
        ->and(Word::query()->where('is_active', true)->count())->toBe(9)
        ->and($course->fresh()?->words_count)->toBe(9);
});

it('lets the repair run wait for an import of the course instead of overwriting its figure with a stale count', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 2]]);
    DB::table('courses')->where('id', $course->id)->update(['words_count' => 99]); // drifted: the run has work to do here

    $results = importRunInParallel([
        function () use ($course): array {
            importHoldBeforeCommit(500_000);

            return importRun(importFile($course, 1, 2, 3));
        },
        function (): int {
            // The import has written its words and its figure by now, and holds them uncommitted.
            usleep(250_000);

            return app(LessonContentRefresher::class)->refreshAll()->changed();
        },
    ]);

    // The run counted the committed words first (2 != 99) and went for the course row; it got it only after the commit and
    // recounted 5. Without the lock it would have written its 2 over the import's 5. (Whichever of the two reaches the row
    // first, the figure ends right; only the lock makes the run wait for an import that is already in flight.)
    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($course->fresh()?->words_count)->toBe(5)
        ->and(Word::query()->where('is_active', true)->count())->toBe(5);
});

it('does not hold up the foreign-key checks of learners while an import keeps the course row', function (): void {
    ['course' => $course] = Fx::course([1 => [1 => 1]]);

    // The import below is parked inside its transaction (after the last write) for a while; a stage of the same
    // course is inserted meanwhile - the insert needs a key-share lock on the course row for its foreign key.
    $results = importRunInParallel([
        function () use ($course): array {
            importHoldBeforeCommit(1_200_000);

            return importRun(importFile($course, 1, 2, 2));
        },
        function () use ($course): float {
            usleep(400_000);
            $startedAt = microtime(true);
            DB::table('stages')->insert(['course_id' => $course->id, 'number' => 9, 'title' => 'S9', 'created_at' => now(), 'updated_at' => now()]);

            return microtime(true) - $startedAt;
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        // `FOR UPDATE` would make the insert wait for the commit, about 0.8 s from here; `FOR NO KEY UPDATE` does not.
        ->and($results[1]['result'])->toBeLessThan(0.4)
        ->and($course->fresh()?->words_count)->toBe(3);
});

it('keeps courses.words_count right when a word is switched off in the admin panel while an import of the course runs', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 2]]);
    $victim = $words['1.1']['active'][0];

    $results = importRunInParallel([
        function () use ($course): array {
            importHoldBeforeCommit(700_000);

            return importRun(importFile($course, 1, 2, 3));
        },
        function () use ($victim): bool {
            // The import has written its words and its figure by now, and holds them uncommitted.
            usleep(300_000);
            $word = Word::query()->findOrFail($victim);
            // What EditWord does: the edit is one UPDATE outside a transaction, then the counters follow.
            $word->update(['is_active' => false]);
            app(LessonContentRefresher::class)->wordChanged($word);

            return true;
        },
    ]);

    // 2 + 3 - 1. Without the course lock the recount of the panel counts what is committed (1: the import's words are not
    // visible yet), waits for the import's row of the course and writes its 1 over the import's figure after the commit.
    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[0]['result']['ok'])->toBeTrue()
        ->and(Word::query()->where('is_active', true)->count())->toBe(4)
        ->and($course->fresh()?->words_count)->toBe(4);
});

it('lets an import wait for the recount of a word edit and end with the right figure, without a deadlock', function (): void {
    ['course' => $course, 'words' => $words] = Fx::course([1 => [1 => 2]]);
    $victim = $words['1.1']['active'][0];

    $results = importRunInParallel([
        function () use ($victim): bool {
            // The recount keeps the course row after it wrote the figure, until its transaction ends.
            DB::listen(static function (QueryExecuted $query): void {
                if (str_starts_with($query->sql, 'update "courses"')) {
                    usleep(700_000);
                }
            });
            $word = Word::query()->findOrFail($victim);
            $word->update(['is_active' => false]);
            app(LessonContentRefresher::class)->wordChanged($word);

            return true;
        },
        function () use ($course): array {
            usleep(300_000);
            $startedAt = microtime(true);
            $report = importRun(importFile($course, 1, 2, 3));

            return $report + ['seconds' => microtime(true) - $startedAt];
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and($results[1]['result']['ok'])->toBeTrue()
        // The import asked for the course row while the recount held it (about 0.4 s of its 0.7 s were left).
        ->and($results[1]['result']['seconds'])->toBeGreaterThan(0.2)
        ->and(Word::query()->where('is_active', true)->count())->toBe(4)
        ->and($course->fresh()?->words_count)->toBe(4);
});
