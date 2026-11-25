<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `score_credits (rating_day)`: the BRIN of 2026_11_25_300101 goes back to a B-tree (S16 review, docs/32 §12.6).
 *
 * A BRIN is only as good as the physical order of the rows. The nightly clean-up (`leagues:prune-score-credits`) deletes the oldest
 * days - the head of the heap - and VACUUM hands that space to the free space map, so the inserts of the next days land in the old
 * pages: a page range then spans months, every range matches `rating_day < ?`, and the planner falls back to a sequential scan of the
 * whole journal (measured: 122 ms per batch of the clean-up; the last, empty batch reads everything). The correlation does not hold
 * by construction, so the index must not depend on it. The B-tree on `rating_day` serves the clean-up's range whatever the order of
 * the heap; its key only grows, so an insert lands on the rightmost leaf (no page splits in the middle, a hot page in memory) - the
 * cheapest B-tree there is. The other readers of the journal go by `(learner_id, rating_day, lexeme_id)` and `score_credits_long_idx`.
 *
 * PostgreSQL only (sqlite keeps the B-tree of 300004). CONCURRENTLY outside the migrator's transaction: the journal holds rows.
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
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS score_credits_rating_day_index ON score_credits (rating_day)');
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS score_credits_rating_day_brin');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS score_credits_rating_day_brin ON score_credits USING brin (rating_day)');
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS score_credits_rating_day_index');
    }

    private function concurrently(): string
    {
        return DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
