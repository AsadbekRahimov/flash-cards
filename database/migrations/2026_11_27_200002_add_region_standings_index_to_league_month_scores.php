<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `league_month_scores_region_standings_idx` (docs/35 §2.4, R6): the table of a region of a v2 month - one category, one region, in the
 * exact order of the table (`points desc, long_words desc, active_days desc, learner_id`) after the equality prefix - so the top, a page
 * after a cursor, the place ("how many are better") and the neighbours of a region are index ranges, as they are for the whole category
 * (`league_month_scores_category_standings_idx`). Without it a rare region (1-2 % of the learners) is a filter over the whole category.
 *
 * Partial (`participant AND region_code IS NOT NULL`): only the rows a region table reads. The learners without a region and the
 * non-participants never touch it, and `participant` is not a key column (every reader asks `participant = true`). Price: a row of the
 * month whose sort columns change (the refresh, every 10 minutes for the recently active; the close) writes one more index entry.
 *
 * The table holds every learner of every month: PostgreSQL builds and drops it CONCURRENTLY, outside the migrator's transaction; inside a
 * caller's transaction (RefreshDatabase) the plain statement is used, as in 2026_11_25_300006.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'league_month_scores_region_standings_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX
            .' ON league_month_scores (league_month_id, category, region_code, points DESC, long_words DESC, active_days DESC, learner_id)'
            .' WHERE participant AND region_code IS NOT NULL');
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
