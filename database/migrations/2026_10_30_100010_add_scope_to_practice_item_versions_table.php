<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The target words, the lesson and the level belong to a VERSION of a task (docs/16 §2.3-§2.4), not to the task: a draft that
 * changes them must not change what the published version hands out. `practice_item_versions` gets
 *   - `target_word_ids` jsonb NOT NULL DEFAULT '[]' - the ids of the target words, ascending;
 *   - `lesson_id` - the lesson, a nullable foreign key that is set NULL when the lesson goes (as `practice_items.lesson_id`);
 *   - `level` - `A1`..`C2`, nullable (as `practice_items.level`).
 * `practice_items.lesson_id`, `practice_items.level` and the rows of `practice_item_words` stay, as the PROJECTION of one version:
 * the published one when the task has it, else the current one - PracticeItemService is the only writer of the three.
 *
 * Every existing version takes the present values of its task (the words of `practice_item_words`, the lesson and the level of
 * `practice_items`): that is what is handed out now, so nothing changes at the moment of the migration.
 *
 * On SQLite a foreign key can be added or dropped only by rebuilding the table, and the rebuild re-creates the indexes WITHOUT their
 * predicate: the partial index of the pending audio is dropped before and put back after (PostgreSQL keeps it as it is).
 */
return new class extends Migration
{
    private const PENDING_INDEX = 'practice_item_versions_audio_pending_idx';

    public function up(): void
    {
        $this->dropPendingIndexOnSqlite();

        Schema::table('practice_item_versions', function (Blueprint $table): void {
            $table->jsonb('target_word_ids')->default('[]');
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('level', 2)->nullable();
        });

        $this->restorePendingIndexOnSqlite();

        $this->backfill();
    }

    public function down(): void
    {
        $this->dropPendingIndexOnSqlite();

        Schema::table('practice_item_versions', function (Blueprint $table): void {
            $table->dropForeign(['lesson_id']);
        });

        Schema::table('practice_item_versions', function (Blueprint $table): void {
            $table->dropColumn(['target_word_ids', 'lesson_id', 'level']);
        });

        $this->restorePendingIndexOnSqlite();
    }

    /** Every version of a task gets the words, the lesson and the level the task has now. */
    private function backfill(): void
    {
        DB::table('practice_items')
            ->select('id', 'lesson_id', 'level')
            ->chunkById(500, function ($items): void {
                $ids = $items->pluck('id')->all();

                /** @var array<int, list<int>> $words item id => its target words, ascending */
                $words = [];
                foreach (DB::table('practice_item_words')->whereIn('item_id', $ids)->orderBy('word_id')->get(['item_id', 'word_id']) as $row) {
                    $words[(int) $row->item_id][] = (int) $row->word_id;
                }

                foreach ($items as $item) {
                    DB::table('practice_item_versions')->where('item_id', $item->id)->update([
                        'target_word_ids' => json_encode($words[(int) $item->id] ?? [], JSON_THROW_ON_ERROR),
                        'lesson_id' => $item->lesson_id,
                        'level' => $item->level,
                    ]);
                }
            });
    }

    private function dropPendingIndexOnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS '.self::PENDING_INDEX);
        }
    }

    private function restorePendingIndexOnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'CREATE INDEX '.self::PENDING_INDEX."
                 ON practice_item_versions (audio_status)
                 WHERE audio_status = 'pending'",
            );
        }
    }
};
