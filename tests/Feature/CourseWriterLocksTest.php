<?php

declare(strict_types=1);

/*
 * The shape of the lock order of the writers of one course (ImportConcurrencyTest has the real races on
 * PostgreSQL): the importer, the decks and the publication all take the course row FIRST in their transaction;
 * the admin panel writes a word as a single statement (never inside a transaction of the panel) and only then
 * recounts the course in a short transaction of its own that takes the course row first. These checks run on
 * every driver, so a change that breaks the order is seen without a PostgreSQL.
 */

use App\Domain\Content\Services\LessonContentRefresher;
use App\Domain\Content\Services\VocabularyImporter;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Stage;
use App\Models\Word;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reads (and, on PostgreSQL, locks) the course row before anything else in the import transaction', function (): void {
    $course = Course::factory()->published()->create();
    $outside = DB::transactionLevel(); // RefreshDatabase wraps the test in one
    $inside = [];
    DB::listen(function (QueryExecuted $query) use (&$inside, $outside): void {
        if (DB::transactionLevel() > $outside) {
            $inside[] = $query->sql;
        }
    });

    $report = app(VocabularyImporter::class)->importAsSystem(json_encode([
        'course' => $course->slug,
        'stage' => ['number' => 1],
        'lesson' => ['number' => 1],
        'words' => [['word' => 'lock', 'translation' => 'замок']],
    ], JSON_THROW_ON_ERROR));

    expect($report->ok())->toBeTrue()
        ->and($inside[0] ?? '')->toStartWith('select * from "courses" where "courses"."id" = ?');

    if (DB::getDriverName() === 'pgsql') {
        // NOT `for update`: that one would stall every foreign-key insert that references the course (ImportConcurrencyTest).
        expect($inside[0])->toEndWith('limit 1 for no key update');
    }
});

it('recounts the course after a word edit in a short transaction of its own that takes the course row first', function (): void {
    $course = Course::factory()->published()->create();
    $word = Word::factory()->for(Lesson::factory()->for(Stage::factory()->create(['course_id' => $course->id]))->create())->create(['is_active' => true]);
    $word->update(['is_active' => false]); // what the panel did: one statement, already committed

    $outside = DB::transactionLevel(); // RefreshDatabase wraps the test in one
    $all = [];
    $inside = [];
    DB::listen(function (QueryExecuted $query) use (&$all, &$inside, $outside): void {
        $all[] = $query->sql;
        if (DB::transactionLevel() > $outside) {
            $inside[] = $query->sql;
        }
    });

    app(LessonContentRefresher::class)->wordChanged($word);

    // Inside: the lock, the count, the figure - in this order; the lesson, its stage and its course are read before.
    expect($inside)->toHaveCount(3)
        ->and($inside[0])->toStartWith('select * from "courses" where "courses"."id" = ?')
        ->and($inside[1])->toStartWith('select count(*) as aggregate from "words"')
        ->and($inside[2])->toStartWith('update "courses" set "words_count" = ?')
        // 5 statements before the lock was added (3 reads, count, update): one more is the whole price of the lock.
        ->and($all)->toHaveCount(6)
        ->and($course->fresh()?->words_count)->toBe(0);

    if (DB::getDriverName() === 'pgsql') {
        expect($inside[0])->toEndWith('limit 1 for no key update');
    }
});

it('keeps the Filament panels out of database transactions: word edits are single statements, never word-then-course', function (): void {
    // LessonContentRefresher::wordChanged runs after the edit's own UPDATE and takes the course row for `recount`.
    // Inside a transaction of the panel that would be "word row, then course row" - the opposite of the importer,
    // which holds the course row and then writes words - and the two could deadlock.
    expect(Filament::getPanels())->not->toBeEmpty();

    foreach (Filament::getPanels() as $id => $panel) {
        expect($panel->hasDatabaseTransactions())->toBeFalse("panel {$id} wraps its actions in a transaction");
    }
});
