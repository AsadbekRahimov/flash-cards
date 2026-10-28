<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two indexes for the hottest learning queries (docs/03 §2.7, §2.8; performance report 2026-10-28, P2-3 / P2-9).
 *
 *  - `word_repetitions_next_card_idx (learner_id, is_hard DESC, next_review_at, id)` is the exact order of
 *    `CardPicker::pickNext` step 1 (`WHERE learner_id = ? AND next_review_at <= now ORDER BY is_hard DESC,
 *    next_review_at, id LIMIT 1`), so the due card is the first matching index entry instead of a sort over
 *    every overdue card of the learner (1 500 overdue cards: 5 977 buffers / 2.8 ms → a handful of buffers,
 *    well under 0.1 ms). It replaces `word_repetitions_learner_hard_due_idx (learner_id, is_hard, next_review_at)`:
 *    the picker is the only query ordering by `is_hard`, and every other lookup by `learner_id` or
 *    `(learner_id, next_review_at)` has its own index (docs/03 §2.8).
 *  - `words_inactive_idx (id) WHERE is_active = false` backs the anti-join form of `DueRepetitionCriteria`
 *    (`NOT EXISTS (SELECT … FROM words WHERE id = word_id AND is_active = false)`): withdrawn words are a small
 *    fraction of the table, so the hash of inactive ids comes from a few index pages instead of a seq scan of `words`.
 *
 * PostgreSQL builds and drops the indexes `CONCURRENTLY`: a plain `CREATE INDEX` holds a SHARE lock on
 * `word_repetitions` for the whole build and every `POST /review` would wait. CONCURRENTLY cannot run inside a
 * transaction block, hence `$withinTransaction = false`. When a caller wraps the migration in a transaction anyway
 * (the test suite runs `migrate:fresh` / `migrate:rollback` inside RefreshDatabase), CONCURRENTLY is impossible and
 * the plain statement is used — the tables are tiny there. An interrupted CONCURRENTLY build leaves an INVALID
 * index behind; `DROP INDEX IF EXISTS` before each build makes a re-run of the migration clean it up.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // Build the replacement before dropping the old index, so the hot path never runs without one.
        $this->createIndex('word_repetitions_next_card_idx', 'word_repetitions', '(learner_id, is_hard DESC, next_review_at, id)');
        $this->dropIndex('word_repetitions_learner_hard_due_idx');

        $this->createIndex('words_inactive_idx', 'words', '(id) WHERE is_active = false');
    }

    public function down(): void
    {
        $this->dropIndex('words_inactive_idx');

        $this->createIndex('word_repetitions_learner_hard_due_idx', 'word_repetitions', '(learner_id, is_hard, next_review_at)');
        $this->dropIndex('word_repetitions_next_card_idx');
    }

    private function createIndex(string $name, string $table, string $definition): void
    {
        // A leftover INVALID index from an interrupted CONCURRENTLY build would make IF NOT EXISTS skip the rebuild.
        $this->dropIndex($name);

        DB::statement("CREATE INDEX {$this->concurrently()}{$name} ON {$table} {$definition}");
    }

    private function dropIndex(string $name): void
    {
        DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
    }

    private function concurrently(): string
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
