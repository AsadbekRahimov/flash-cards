<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `score_credits_long_idx` (docs/32 §5.2, §12.6; S9): the tie-break `long_words` of a v2 month is the number of DISTINCT lexemes whose
 * long repetition added month points and was not voided - read from the journal by every refresh of the month (the scheduler every
 * 10 minutes for the recently active learners, the viewer's own row, the close). Without an index of its own that read is a range of
 * the whole month of the journal (a BRIN bitmap scan of every credit of the month - 30 000 pages for 2 million credits, measured on
 * PostgreSQL); with it, an index-only scan of the long credits only (8 000 pages, no heap: −75 % of the reads, 145 ms for 20 000
 * learners), one learner = a few index pages.
 *
 * Partial: only the rows the count reads (`kind = 'long' AND month_points > 0 AND voided_at IS NULL`, ~10–25 % of the journal), so an
 * ordinary credit (`new`, `review`, a long one cut by the cap) never touches it; a void is rare (administrator) and takes the row out.
 * `(learner_id, rating_day, lexeme_id)`: the learner first - one learner is a range, a set of learners a merge join; `lexeme_id` makes
 * the DISTINCT an index-only read.
 *
 * The journal may already hold rows when this runs (the word points live), so PostgreSQL builds and drops it CONCURRENTLY, outside
 * the migrator's transaction; inside a caller's transaction (RefreshDatabase) the plain statement is used, as in 2026_11_25_300006.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'score_credits_long_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX
            ." ON score_credits (learner_id, rating_day, lexeme_id) WHERE kind = 'long' AND month_points > 0 AND voided_at IS NULL");
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
