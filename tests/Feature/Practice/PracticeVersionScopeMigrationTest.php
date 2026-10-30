<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\Lesson;
use App\Models\PracticeItem;
use App\Models\PracticeItemVersion;
use App\Models\Stage;
use App\Models\Word;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
 * 2026_10_30_100010_add_scope_to_practice_item_versions_table: the target words, the lesson and the level belong to a VERSION.
 * The columns and their defaults, the backfill of the versions that exist (each takes the words, the lesson and the level its task
 * has now), the foreign key of the lesson, and `down()` - on SQLite the table is rebuilt for the foreign key, and the partial index of
 * the pending audio must come out of it with its predicate.
 */

const PVS_MIGRATION = '2026_10_30_100010_add_scope_to_practice_item_versions_table';

function pvs_migration(): Migration
{
    return require database_path('migrations/'.PVS_MIGRATION.'.php');
}

/** A course with a lesson, an empty second lesson and `$words` words in the first. @return array{0: Course, 1: Lesson, 2: Lesson, 3: list<Word>} */
function pvs_course(int $words = 3): array
{
    $course = Course::factory()->published()->create();
    $stage = Stage::factory()->create(['course_id' => $course->id, 'number' => 1]);
    $one = Lesson::factory()->for($stage)->create(['number' => 1]);
    $two = Lesson::factory()->for($stage)->create(['number' => 2]);
    $made = [];
    for ($i = 0; $i < $words; $i++) {
        $made[] = Word::factory()->for($one)->create();
    }

    return [$course, $one, $two, $made];
}

/** A raw `practice_items` row (the shape the table had before the migration: the place and the words live on the task). */
function pvs_item(Course $course, string $key, ?int $lessonId, ?string $level): int
{
    return (int) DB::table('practice_items')->insertGetId([
        'course_id' => $course->id, 'lesson_id' => $lessonId, 'type' => 'sentence', 'key' => $key, 'level' => $level,
        'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A raw version row of the old shape (no `target_word_ids`, `lesson_id`, `level`). */
function pvs_version(int $itemId, int $number): int
{
    return (int) DB::table('practice_item_versions')->insertGetId([
        'item_id' => $itemId, 'number' => $number, 'state' => 'draft', 'content' => json_encode(['n' => $number], JSON_THROW_ON_ERROR),
        'audio_status' => 'pending', 'created_at' => now(),
    ]);
}

function pvs_pending_index(): string
{
    $definition = DB::getDriverName() === 'pgsql'
        ? DB::table('pg_indexes')->where('indexname', 'practice_item_versions_audio_pending_idx')->value('indexdef')
        : DB::table('sqlite_master')->where('type', 'index')->where('name', 'practice_item_versions_audio_pending_idx')->value('sql');

    return (string) $definition;
}

it('adds the three columns: the words as a jsonb list that defaults to empty, the lesson and the level as nulls', function (): void {
    expect(Schema::hasColumns('practice_item_versions', ['target_word_ids', 'lesson_id', 'level']))->toBeTrue();

    [$course] = pvs_course();
    $itemId = pvs_item($course, 'defaults', null, null);
    $versionId = pvs_version($itemId, 1);

    $row = DB::table('practice_item_versions')->where('id', $versionId)->first();
    expect(json_decode((string) $row?->target_word_ids, true))->toBe([])
        ->and($row?->lesson_id)->toBeNull()
        ->and($row?->level)->toBeNull();

    // The model casts the list, and the version is saved with all three.
    $version = PracticeItemVersion::factory()->create(['item_id' => $itemId, 'number' => 2, 'target_word_ids' => [3, 5], 'level' => 'B1']);
    expect($version->fresh()?->target_word_ids)->toBe([3, 5])
        ->and($version->fresh()?->level)->toBe('B1');
});

it('gives every existing version the words, the lesson and the level its task has now', function (): void {
    [$course, $one, $two, $words] = pvs_course(3);
    $migration = pvs_migration();
    $migration->down();

    // The old shape: the place and the words on the task, a published task with a newer draft behind it, a task with nothing.
    $full = pvs_item($course, 'full', $one->id, 'A1');
    DB::table('practice_item_words')->insert([
        ['item_id' => $full, 'word_id' => $words[2]->id], ['item_id' => $full, 'word_id' => $words[0]->id],
    ]);
    $fullVersions = [pvs_version($full, 1), pvs_version($full, 2), pvs_version($full, 3)];
    $bare = pvs_item($course, 'bare', null, null);
    $bareVersion = pvs_version($bare, 1);
    $other = pvs_item($course, 'other', $two->id, 'C2');
    DB::table('practice_item_words')->insert(['item_id' => $other, 'word_id' => $words[1]->id]);
    $otherVersion = pvs_version($other, 1);
    $noVersion = pvs_item($course, 'no-version', $one->id, 'B1');

    $migration->up();

    $of = static fn (int $id): PracticeItemVersion => PracticeItemVersion::query()->findOrFail($id);
    foreach ($fullVersions as $id) {
        // the words ascending, whatever the order they were linked in
        expect($of($id)->target_word_ids)->toBe([$words[0]->id, $words[2]->id])
            ->and($of($id)->lesson_id)->toBe($one->id)
            ->and($of($id)->level)->toBe('A1');
    }
    expect($of($bareVersion)->target_word_ids)->toBe([])
        ->and($of($bareVersion)->lesson_id)->toBeNull()
        ->and($of($bareVersion)->level)->toBeNull()
        ->and($of($otherVersion)->target_word_ids)->toBe([$words[1]->id])
        ->and($of($otherVersion)->lesson_id)->toBe($two->id)
        ->and($of($otherVersion)->level)->toBe('C2')
        // the rest of the version is as it was
        ->and($of($fullVersions[1])->content)->toBe(['n' => 2])
        ->and($of($fullVersions[1])->number)->toBe(2)
        ->and($of($fullVersions[1])->audio_status->value)->toBe('pending')
        ->and(DB::table('practice_items')->where('id', $noVersion)->exists())->toBeTrue();
});

it('backfills in chunks: more tasks than one chunk, every one of them', function (): void {
    [$course, $one, , $words] = pvs_course(2);
    $migration = pvs_migration();
    $migration->down();

    $ids = [];
    foreach (range(1, 1100) as $n) {
        $ids[] = pvs_item($course, "bulk-{$n}", $n % 2 === 0 ? $one->id : null, $n % 3 === 0 ? 'A2' : null);
    }
    foreach (array_chunk($ids, 200) as $chunk) {
        DB::table('practice_item_versions')->insert(array_map(static fn (int $id): array => [
            'item_id' => $id, 'number' => 1, 'state' => 'draft', 'content' => '{}', 'audio_status' => 'none', 'created_at' => now(),
        ], $chunk));
        DB::table('practice_item_words')->insert(array_map(static fn (int $id): array => ['item_id' => $id, 'word_id' => $words[$id % 2]->id], $chunk));
    }

    $migration->up();

    expect(DB::table('practice_item_versions')->count())->toBe(1100)
        ->and(DB::table('practice_item_versions')->whereNull('lesson_id')->count())->toBe(550)
        ->and(DB::table('practice_item_versions')->where('lesson_id', $one->id)->count())->toBe(550)
        ->and(DB::table('practice_item_versions')->where('level', 'A2')->count())->toBe(366);
    // The last task of the last chunk, and the first of the first.
    foreach ([$ids[0], $ids[1099]] as $id) {
        $version = PracticeItemVersion::query()->where('item_id', $id)->sole();
        expect($version->target_word_ids)->toBe([$words[$id % 2]->id]);
    }
});

it('sets the lesson of every version to NULL when the lesson goes, as the task does', function (): void {
    [$course, $one] = pvs_course(1);
    $item = PracticeItem::factory()->forLesson($one)->published()->create();
    $draft = PracticeItemVersion::factory()->create(['item_id' => $item->id, 'number' => 2]);
    expect(PracticeItemVersion::query()->where('item_id', $item->id)->where('lesson_id', $one->id)->count())->toBe(2);

    Lesson::query()->whereKey($one->id)->delete();

    expect(PracticeItemVersion::query()->where('item_id', $item->id)->whereNotNull('lesson_id')->count())->toBe(0)
        ->and($item->fresh()?->lesson_id)->toBeNull()
        ->and($draft->fresh()?->number)->toBe(2);
});

it('rolls back to the table as it was, with the partial index of the pending audio and the data, and applies again', function (): void {
    [$course, $one, , $words] = pvs_course(2);
    $itemId = pvs_item($course, 'cycle', $one->id, 'A1');
    DB::table('practice_item_words')->insert(['item_id' => $itemId, 'word_id' => $words[0]->id]);
    $versionId = pvs_version($itemId, 1);
    $migration = pvs_migration();

    $migration->down();

    expect(Schema::hasColumn('practice_item_versions', 'target_word_ids'))->toBeFalse()
        ->and(Schema::hasColumn('practice_item_versions', 'lesson_id'))->toBeFalse()
        ->and(Schema::hasColumn('practice_item_versions', 'level'))->toBeFalse()
        ->and(pvs_pending_index())->toContain('audio_status')->toContain('WHERE')->toContain("'pending'")
        ->and(array_column(Schema::getIndexes('practice_item_versions'), 'name'))->toContain('practice_item_versions_item_id_number_unique')
        ->and(DB::table('practice_item_versions')->where('id', $versionId)->value('content'))->not->toBeNull();

    $migration->up();

    $version = PracticeItemVersion::query()->findOrFail($versionId);
    expect(Schema::hasColumns('practice_item_versions', ['target_word_ids', 'lesson_id', 'level']))->toBeTrue()
        ->and($version->target_word_ids)->toBe([$words[0]->id])
        ->and($version->lesson_id)->toBe($one->id)
        ->and($version->level)->toBe('A1')
        ->and(pvs_pending_index())->toContain('audio_status')->toContain('WHERE')->toContain("'pending'")
        ->and(array_column(Schema::getIndexes('practice_item_versions'), 'name'))->toContain('practice_item_versions_item_id_number_unique');
});

it('keeps the foreign keys of the versions through the rollback and the second application', function (): void {
    $migration = pvs_migration();
    $migration->down();
    $migration->up();

    $keys = array_map(static fn (array $key): string => implode(',', $key['columns']).'>'.$key['foreign_table'].':'.$key['on_delete'], Schema::getForeignKeys('practice_item_versions'));
    sort($keys);

    expect($keys)->toBe([
        'created_by_user_id>users:set null',
        'item_id>practice_items:cascade',
        'lesson_id>lessons:set null',
        'published_by_user_id>users:set null',
        'reviewed_by_user_id>users:set null',
    ]);
});
