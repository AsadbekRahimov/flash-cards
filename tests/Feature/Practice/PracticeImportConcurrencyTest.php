<?php

declare(strict_types=1);

use App\Domain\Content\Services\VocabularyImporter;
use App\Domain\Practice\Services\PracticeImporter;
use App\Domain\Practice\Services\PracticeItemService;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\User;
use App\Models\Word;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkSafe;

/*
 * docs/16 §9 rule 12, §14.2: the authoring writes under the lock of the course taken FIRST (CoursePublisher::lockForWriting), so
 * the writers of one course take turns - the import of the tasks with another import of the same file, with the import of the
 * words of the course, with an editor saving the same task. Real races: every contender is a forked process with its own
 * PostgreSQL connection, released at the same instant. Needs committed rows (DatabaseTruncation) and runs only on pgsql with
 * pcntl. Dictations are left out: a sync queue would voice them in the child.
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
 * Runs each closure in a forked child with a fresh connection, all released at the same instant; what they return, in task order.
 *
 * @param  list<Closure(): mixed>  $tasks
 * @return list<array{ok: bool, value?: mixed, error?: string}>
 */
function pic_race(array $tasks): array
{
    ForkSafe::releaseConnections();

    $dir = sys_get_temp_dir().'/lexiflow-practice-import-race-'.bin2hex(random_bytes(4));
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
                $out = ['ok' => true, 'value' => $task()];
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

/** The audit row is the last write of an import and of a move: every transaction keeps what it holds for a while before it commits. */
function pic_hold_before_commit(int $microseconds = 300_000): void
{
    AuditLog::created(static function () use ($microseconds): void {
        usleep($microseconds);
    });
}

/** A published course `race` with module 1 / lesson 1 and the words mother, father, tomorrow, committed. @return array{0: Course, 1: array<string, int>} */
function pic_world(): array
{
    $course = Course::factory()->published()->create(['slug' => 'race']);
    $lesson = Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id, 'number' => 1]))->create(['number' => 1]);
    $words = [];
    foreach (['mother', 'father', 'tomorrow'] as $text) {
        $words[$text] = Word::factory()->for($lesson)->create(['word' => $text])->id;
    }

    return [$course, $words];
}

/**
 * A file of `$n` sentences with the keys `{prefix}-1..n`.
 */
function pic_file(string $prefix, int $n, string $target = 'mother'): string
{
    $items = [];
    for ($i = 1; $i <= $n; $i++) {
        $items[] = [
            'key' => "{$prefix}-{$i}", 'type' => 'sentence', 'lesson' => ['stage' => 1, 'number' => 1], 'level' => 'A1', 'target_words' => [$target],
            'prompt' => ['ru' => "Предложение {$i}.", 'uz' => "Gap {$i}."],
            'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
            'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
            'explanation' => ['ru' => 'Порядок слов.', 'uz' => "So'z tartibi."],
        ];
    }

    return json_encode(['format' => 'lexiflow-practice/1', 'course' => 'race', 'items' => $items], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

/** @return array{ok: bool, added: int, updated: int, skipped: int} */
function pic_import(string $json, bool $publish = false): array
{
    $report = app(PracticeImporter::class)->importAsSystem($json, $publish);

    return ['ok' => $report->ok(), 'added' => $report->added, 'updated' => $report->updated, 'skipped' => $report->skipped];
}

it('imports a file submitted twice at once only once: the second waits for the commit and skips what is there', function (): void {
    pic_world();
    $file = pic_file('twice', 6);

    $results = pic_race(array_fill(0, 2, function () use ($file): array {
        pic_hold_before_commit();

        return pic_import($file);
    }));

    // Without the lock the second import does not see the tasks of the first (uncommitted) and dies on the unique (course_id, key).
    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('value.ok')->unique()->all())->toBe([true])
        ->and(collect($results)->pluck('value.added')->sort()->values()->all())->toBe([0, 6])
        ->and(collect($results)->pluck('value.skipped')->sort()->values()->all())->toBe([0, 6])
        ->and(PracticeItem::query()->count())->toBe(6)
        ->and(PracticeItemVersion::query()->count())->toBe(6);
});

it('lets the import of the tasks and the import of the words of one course take turns without a deadlock', function (): void {
    [$course] = pic_world();
    $words = json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 2, 'title' => 'Second'],
        'words' => [['word' => 'sister', 'translation' => 'сестра'], ['word' => 'brother', 'translation' => 'брат']],
    ], JSON_THROW_ON_ERROR);

    $results = pic_race([
        function () use ($words): array {
            pic_hold_before_commit();
            $report = app(VocabularyImporter::class)->importAsSystem($words);

            return ['ok' => $report->ok(), 'added' => $report->added];
        },
        function (): array {
            pic_hold_before_commit();

            return pic_import(pic_file('turns', 4));
        },
    ]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('value.ok')->unique()->all())->toBe([true])
        ->and(PracticeItem::query()->count())->toBe(4)
        ->and(Word::query()->where('is_active', true)->count())->toBe(5)
        ->and($course->fresh()?->words_count)->toBe(5);
});

it('numbers the versions of one task without a gap or a repeat when two editors save it at once', function (): void {
    [$course, $words] = pic_world();
    $admin = User::factory()->create();
    $item = app(PracticeItemService::class)->save(null, $course, [
        'type' => 'sentence', 'key' => 'edited', 'word_ids' => [$words['mother']],
        'content' => [
            'prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."],
            'fragments' => ['my', 'mother', 'is', 'a', 'teacher'], 'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
        ],
    ], $admin)->item;

    $edit = function (string $ru) use ($course, $item, $words, $admin): int {
        // A save writes no audit row: hold the transaction right after its version is inserted, so the two really overlap.
        PracticeItemVersion::created(static function (): void {
            usleep(300_000);
        });

        return app(PracticeItemService::class)->saveVersion(
            PracticeItem::query()->findOrFail($item->id),
            $course,
            ['word_ids' => [$words['mother']], 'content' => [
                'prompt' => ['ru' => $ru, 'uz' => "Onam o'qituvchi."],
                'fragments' => ['my', 'mother', 'is', 'a', 'teacher'], 'accepted' => [['my', 'mother', 'is', 'a', 'teacher']],
            ]],
            User::query()->findOrFail($admin->id),
        )->number;
    };

    $results = pic_race([fn (): int => $edit('Первая правка.'), fn (): int => $edit('Вторая правка.')]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('value')->sort()->values()->all())->toBe([2, 3])
        ->and(PracticeItemVersion::query()->where('item_id', $item->id)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and(PracticeItem::query()->findOrFail($item->id)->current_version_id)->toBe(PracticeItemVersion::query()->where('item_id', $item->id)->max('id'));
});

it('moves a task one step when two admins press «Проверено» at once: one audit record', function (): void {
    [$course, $words] = pic_world();
    $admin = User::factory()->create();
    $item = app(PracticeItemService::class)->save(null, $course, [
        'type' => 'collocation', 'key' => 'review-race', 'word_ids' => [$words['tomorrow']],
        'content' => ['text' => "Let's ___ a meeting.", 'options' => ['have', 'make', 'do'], 'correct' => ['have'], 'translation' => ['ru' => 'Встреча.', 'uz' => 'Uchrashuv.'], 'explanation' => ['ru' => 'Сочетание.', 'uz' => 'Birikma.']],
    ], $admin)->item;

    $review = function () use ($item, $admin): string {
        pic_hold_before_commit();
        app(PracticeItemService::class)->markReviewed(PracticeItem::query()->findOrFail($item->id), User::query()->findOrFail($admin->id));

        return PracticeItem::query()->findOrFail($item->id)->status->value;
    };

    $results = pic_race([$review, $review]);

    expect(collect($results)->pluck('ok')->unique()->all())->toBe([true], json_encode($results))
        ->and(collect($results)->pluck('value')->unique()->all())->toBe(['reviewed'])
        ->and(AuditLog::query()->where('action', 'practice_item.reviewed')->count())->toBe(1);
});

it('publishes a task once when two admins press «Опубликовать» at once, and archives it once when two press «В архив»', function (): void {
    [$course, $words] = pic_world();
    $admin = User::factory()->create();
    $service = app(PracticeItemService::class);
    $item = $service->save(null, $course, [
        'type' => 'sentence', 'key' => 'publish-race', 'word_ids' => [$words['mother']],
        'content' => [
            'prompt' => ['ru' => 'Моя мама — учитель.', 'uz' => "Onam o'qituvchi."], 'fragments' => ['my', 'mother', 'is', 'a', 'teacher'],
            'accepted' => [['my', 'mother', 'is', 'a', 'teacher']], 'explanation' => ['ru' => 'Порядок слов.', 'uz' => "So'z tartibi."],
        ],
    ], $admin)->item;
    $service->markReviewed($item, $admin);

    $publish = function () use ($item, $admin): string {
        pic_hold_before_commit();
        app(PracticeItemService::class)->publish(PracticeItem::query()->findOrFail($item->id), User::query()->findOrFail($admin->id));

        return PracticeItem::query()->findOrFail($item->id)->status->value;
    };
    $published = pic_race([$publish, $publish]);

    expect(collect($published)->pluck('ok')->unique()->all())->toBe([true], json_encode($published))
        ->and(AuditLog::query()->where('action', 'practice_item.published')->count())->toBe(1);

    $archive = function () use ($item, $admin): string {
        pic_hold_before_commit();
        app(PracticeItemService::class)->archive(PracticeItem::query()->findOrFail($item->id), User::query()->findOrFail($admin->id));

        return PracticeItem::query()->findOrFail($item->id)->status->value;
    };
    $archived = pic_race([$archive, $archive]);

    expect(collect($archived)->pluck('ok')->unique()->all())->toBe([true], json_encode($archived))
        ->and(collect($archived)->pluck('value')->unique()->all())->toBe(['archived'])
        ->and(AuditLog::query()->where('action', 'practice_item.archived')->count())->toBe(1);
});
