<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tables that are mostly UPDATEd in place get free room in every page (fillfactor), so the updates can be HOT (no index
 * write) - and `word_repetitions` / `training_reviews` are vacuumed at 2% of dead rows instead of the default 20%.
 * New pages only: existing ones keep their fill until the table is rewritten. PostgreSQL only (sqlite: nothing to tune);
 * the statements are fixed strings, no input.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE learner_daily_stats SET (fillfactor = 70)');
        DB::statement('ALTER TABLE battle_participants SET (fillfactor = 70)');
        DB::statement('ALTER TABLE word_repetitions SET (fillfactor = 90, autovacuum_vacuum_scale_factor = 0.02)');
        DB::statement('ALTER TABLE training_reviews SET (autovacuum_vacuum_scale_factor = 0.02)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE learner_daily_stats RESET (fillfactor)');
        DB::statement('ALTER TABLE battle_participants RESET (fillfactor)');
        DB::statement('ALTER TABLE word_repetitions RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE training_reviews RESET (autovacuum_vacuum_scale_factor)');
    }
};
