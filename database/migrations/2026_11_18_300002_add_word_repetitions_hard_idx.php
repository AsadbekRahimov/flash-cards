<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `word_repetitions_hard_idx (learner_id, next_review_at) WHERE is_hard = true OR lapses >= 1` for the candidates of the round of hard
 * words (`HardRoundService::candidates`, docs/06 §14), read on every `/today` that has no round of the day yet. The query that
 * docs/03 §2.8 waited for before bringing a partial index on `is_hard` back: it filters by `is_hard` or by a lapse count. Without it
 * the read walks every row of the learner. The query writes the predicate exactly as here (constants, not bindings: the planner
 * proves a partial index applies only to the same condition, see `word_repetitions_in_steps_idx`); the learner's own threshold
 * (`learning.hard_round.lapses_from`, >= 1) is a second condition that implies it.
 *
 * The key is the term, not the lapse count: a candidate is a hard word whose term is due or comes within `due_within_days`
 * (`next_review_at <= now + N days`, the round never pulls a word further ahead - else SM-2 would inflate its interval), so the read is
 * a range `learner_id = ? AND next_review_at <= ?` over the few hard words of the next days; the order (hard, lapses, ease) is that of
 * a small set. A key on `lapses` could not serve that range: every row with a failure would be read and filtered on the heap.
 *
 * One more index on the hottest update table, accepted: the index holds only the rows that failed at least once, and an entry is
 * touched when `is_hard` / `lapses` / `next_review_at` change - i.e. on every grade of such a word (docs/03 §2.8).
 *
 * PostgreSQL builds and drops it `CONCURRENTLY` (no SHARE lock: `POST /review` keeps writing), which cannot run inside a transaction
 * block - hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests) the plain statement is used.
 * `DROP INDEX IF EXISTS` before the build clears an INVALID index left by an interrupted run. SQLite takes the same statement.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'word_repetitions_hard_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement(
            'CREATE INDEX '.$this->concurrently().self::INDEX
            .' ON word_repetitions (learner_id, next_review_at) WHERE is_hard = true OR lapses >= 1',
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
