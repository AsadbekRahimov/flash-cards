<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `word_repetitions_in_steps_idx (learner_id) WHERE state IN ('new', 'learning', 'relearning')` for the round's `pending`
 * counter (`CardPicker::stepCounts`, docs/06 §13.2): the grades the learner's cards in minute steps still owe, read on every
 * `/next` and `/review` of a daily session. No index starts with `state`, so the counter walked every row of the learner by
 * `learner_id` and filtered — a learner with 5 000 rows cost 5 007 buffers and 24 ms per step (EXPLAIN ANALYZE on a set of
 * 2 000 000 rows, 400 learners × 5 000, 8 of them in steps). The rows in minute steps are a handful per learner at any time,
 * so the partial index is tiny (48 kB beside a 284 MB table) and the counter is an index scan of those few entries: 11 buffers,
 * 0.07 ms. The predicate must be written in the query exactly as here (`state IN (...)`, not `state <> 'review'`): the planner
 * proves the partial index applies only for the same list.
 *
 * One more index on the hottest update table, accepted: every grade already changes `next_review_at` (in
 * `word_repetitions_next_card_idx`), so no grade was a HOT update before either; an entry is written only while the row is in
 * its steps and dropped when it graduates.
 *
 * PostgreSQL builds and drops it `CONCURRENTLY` (no SHARE lock: `POST /review` keeps writing), which cannot run inside a
 * transaction block - hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests) the plain
 * statement is used, as in 2026_10_28_000001. `DROP INDEX IF EXISTS` before the build clears an INVALID index left by an
 * interrupted run. SQLite takes the same statement (partial indexes are supported) without CONCURRENTLY.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'word_repetitions_in_steps_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement(
            'CREATE INDEX '.$this->concurrently().self::INDEX
            ." ON word_repetitions (learner_id) WHERE state IN ('new', 'learning', 'relearning')",
        );
    }

    public function down(): void
    {
        $this->dropIndex();
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
