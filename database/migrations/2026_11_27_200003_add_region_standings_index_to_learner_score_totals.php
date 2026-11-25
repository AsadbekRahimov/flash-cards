<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `learner_score_totals_region_standings_idx` (docs/35 §2.4, R6): the "all time" table of a region - one category, one region, in the
 * exact order of the table after the equality prefix, next to `learner_score_totals_standings_idx` of the whole category. The same
 * reasons and the same shape as `league_month_scores_region_standings_idx` (2026_11_27_200002): partial
 * (`participant AND region_code IS NOT NULL`), so the learners without a region and the non-participants never write to it.
 *
 * Price: a recompute of the totals (ScoreTotalsService: the learners whose rating days changed since the last run, every 10 minutes; a
 * learner's hook; the reconcile after the close of a month) that changes the points of a learner with a region writes one more entry.
 *
 * CONCURRENTLY on PostgreSQL outside the migrator's transaction, the plain statement inside a caller's one (RefreshDatabase).
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'learner_score_totals_region_standings_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX
            .' ON learner_score_totals (category, region_code, points DESC, long_words DESC, active_days DESC, learner_id)'
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
