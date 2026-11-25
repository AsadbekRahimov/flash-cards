<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fourth personal mode of `training_sessions`, `lesson_review` - the review of a lesson just studied (docs/04 A.3, docs/06 §15).
 *
 * No column: the state of the round lives in `meta` (added with `hard_review`, 2026_11_18_300001), `mode` is a plain varchar(16)
 * without a CHECK, and the shape invariant `mode <> 'group_lesson' => learner_id` already holds. Only the partial unique index
 * `training_sessions_one_open_lesson_review_per_learner (learner_id) WHERE status = 'open' AND mode = 'lesson_review'`: at most one
 * open review round per learner, whatever bypasses the learner lock of LessonReviewService (two tabs, two lessons).
 *
 * `training_sessions` is written by every answer of every learner, so PostgreSQL builds and drops the index `CONCURRENTLY` (no lock
 * that would stop the writes for the build), which cannot run inside a transaction block - hence `$withinTransaction = false`; inside
 * a caller's transaction (RefreshDatabase in tests) the plain statement is used, as in 2026_11_25_300003. `DROP INDEX IF EXISTS`
 * first clears an INVALID index an interrupted concurrent build leaves behind. SQLite (tests) gets the plain statements.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'training_sessions_one_open_lesson_review_per_learner';

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        $this->dropIndex();

        DB::statement(
            'CREATE UNIQUE INDEX '.$this->concurrently().self::INDEX." ON training_sessions (learner_id) WHERE status = 'open' AND mode = 'lesson_review'",
        );
    }

    public function down(): void
    {
        if ($this->supported()) {
            $this->dropIndex();
        }
    }

    private function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }

    private function dropIndex(): void
    {
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX);
    }

    private function concurrently(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' && DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
