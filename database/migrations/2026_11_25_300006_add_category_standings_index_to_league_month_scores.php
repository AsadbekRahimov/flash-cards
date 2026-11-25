<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `league_month_scores_category_standings_idx` (docs/32 §2.6): the table of a v2 month is per category, in the order
 * `points desc, long_words desc, active_days desc, learner_id` - the top, a page and "how many are better" read it in order.
 * The v1 index (`league_month_scores_standings_idx`) stays: the v1 months and their archive keep using it.
 *
 * The table gains every active learner every month, so PostgreSQL builds and drops the index `CONCURRENTLY`, outside the
 * migrator's transaction (`$withinTransaction = false`); inside a caller's transaction (RefreshDatabase in tests) the plain
 * statement is used, as in 2026_11_16_200002.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'league_month_scores_category_standings_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX
            .' ON league_month_scores (league_month_id, category, participant, points DESC, long_words DESC, active_days DESC, learner_id)');
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
