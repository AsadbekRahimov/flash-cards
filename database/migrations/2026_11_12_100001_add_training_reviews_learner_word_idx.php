<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `training_reviews_learner_word_idx (learner_id, word_id, id)` for `LeechInsight::confusedWith` (docs/06 §12): the last five
 * wrong `reverse` answers of one (learner, word) - `WHERE learner_id = ? AND word_id = ? AND exercise = 'reverse' AND
 * quality < 3 ORDER BY id DESC LIMIT 5`. The existing indexes start with `(learner_id, created_at)` (every review of the learner
 * is read and filtered) or `(word_id, quality)` (every review of the word of all learners); this one is the exact prefix and the
 * order of the query, so the pick is a short backward index scan.
 *
 * It is one more index on the hottest insert table (+1 index write per answer), accepted: the plain B-tree entry is small and
 * append-only in its `id` tail. `(word_id, quality)` stays: although no query of `app/` reads by it, it is the index of the
 * `training_reviews.word_id` foreign key (`ON DELETE RESTRICT`), which PostgreSQL checks on every delete of a word.
 *
 * PostgreSQL builds and drops it `CONCURRENTLY` (no SHARE lock: `POST /review` keeps inserting), which cannot run inside a
 * transaction block - hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests) the plain
 * statement is used, as in 2026_10_28_000001. `DROP INDEX IF EXISTS` before the build clears an INVALID index left by an
 * interrupted run.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'training_reviews_learner_word_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX.' ON training_reviews (learner_id, word_id, id)');
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
