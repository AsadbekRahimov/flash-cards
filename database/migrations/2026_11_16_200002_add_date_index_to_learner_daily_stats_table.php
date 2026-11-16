<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `learner_daily_stats_date_idx (date)` for the monthly rating (docs/13 §7.6): the refresh of `league_month_scores` reads
 * the day rows of a date range of EVERY learner (`WHERE date BETWEEN ? AND ?`), which the unique `(learner_id, date)`
 * cannot serve without the learner. `date` never changes after the insert, so the index costs the review transaction
 * one entry per NEW day row (one a day per learner) and leaves its UPDATEs HOT-eligible — unlike an index on
 * `updated_at` would (rejected for that reason).
 *
 * PostgreSQL builds and drops it `CONCURRENTLY` (no SHARE lock on the hottest UPDATE table), which cannot run inside a
 * transaction block — hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests) the
 * plain statement is used, as in 2026_11_12_100001. `DROP INDEX IF EXISTS` first clears an INVALID index of an
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

    private const INDEX = 'learner_daily_stats_date_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX.' ON learner_daily_stats (date)');
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
