<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The region of the rating tables (docs/35 §2.4, R6; docs/32 §12.11):
 *
 *   learner_score_periods.region_code — the learner's region at the first credit of the period (`learners.region_code` read by the
 *                                        same INSERT that fixes the category, ScorePeriodService); an empty one of an open month is
 *                                        filled once by the first region chosen (MonthlyRatingService::regionChanged) and is never
 *                                        changed afterwards: a change of the region moves the NEXT month only;
 *   league_month_scores.region_code   — the region of the learner's month (from the period above), written by the refresh of a v2 month;
 *   learner_score_totals.region_code  — the CURRENT region of the learner ("all time" is "people of the region"), written by the
 *                                        recompute of the totals (ScoreTotalsService).
 *
 * NULL = no region. No CHECK: the codes live in config/regions.php. A nullable column without a default is a change of the catalog
 * only on PostgreSQL (no rewrite of the table), so it runs inside the migrator's transaction. The indexes are 2026_11_27_200002/3.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['learner_score_periods', 'league_month_scores', 'learner_score_totals'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('region_code', 8)->nullable();
            });
        }

        // The months still open were started before the column existed: their periods take the learner's region now - what the first
        // choice would have filled anyway (MonthlyRatingService::regionChanged). Closed months stay without a region (no tables of a
        // region for them). At most one row per learner and open month; `leagues:refresh-month --full` then carries it to the rows of
        // the month and to "all time".
        DB::update(
            'update learner_score_periods set region_code = (select l.region_code from learners l where l.id = learner_score_periods.learner_id) '
            ."where region_code is null and period = 'month' and starts_on in (select m.starts_on from league_months m where m.status = 'open')",
        );
    }

    public function down(): void
    {
        foreach (['learner_score_totals', 'league_month_scores', 'learner_score_periods'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('region_code');
            });
        }
    }
};
