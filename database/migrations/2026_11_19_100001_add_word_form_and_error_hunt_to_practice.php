<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Word form" and "Find the mistake" (docs/16 §5.6, §5.7): two new types of authored task and two new solo modes of the same name.
 * The columns (`varchar(12)`) hold both values already; on PostgreSQL the CHECKs of `practice_items.type` and
 * `practice_sessions.mode` learn them. `practice_records_mode_check` is left alone: the new modes keep no record. SQLite has no
 * such CHECKs: nothing to do there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE practice_items DROP CONSTRAINT IF EXISTS practice_items_type_check');
        DB::statement("ALTER TABLE practice_items ADD CONSTRAINT practice_items_type_check CHECK (type IN ('collocation','sentence','dictation','word_form','error_hunt'))");
        DB::statement('ALTER TABLE practice_sessions DROP CONSTRAINT IF EXISTS practice_sessions_mode_check');
        DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation','pairs_timed','word_form','error_hunt'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // The narrower CHECKs cannot hold these rows, and a rollback must not lose them silently.
        if (DB::table('practice_items')->whereIn('type', ['word_form', 'error_hunt'])->exists()) {
            throw new RuntimeException('Cannot roll back: practice_items has tasks of the types word_form / error_hunt. Delete them first.');
        }
        if (DB::table('practice_sessions')->whereIn('mode', ['word_form', 'error_hunt'])->exists()) {
            throw new RuntimeException('Cannot roll back: practice_sessions has rows of the modes word_form / error_hunt. Delete them first.');
        }

        DB::statement('ALTER TABLE practice_items DROP CONSTRAINT IF EXISTS practice_items_type_check');
        DB::statement("ALTER TABLE practice_items ADD CONSTRAINT practice_items_type_check CHECK (type IN ('collocation','sentence','dictation'))");
        DB::statement('ALTER TABLE practice_sessions DROP CONSTRAINT IF EXISTS practice_sessions_mode_check');
        DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation','pairs_timed'))");
    }
};
