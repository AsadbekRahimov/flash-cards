<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX exam_sessions_one_open_per_group_unique
             ON exam_sessions (telegram_group_id)
             WHERE status = 'open'",
        );

        DB::statement(
            "CREATE UNIQUE INDEX training_sessions_one_open_per_group_lesson_unique
             ON training_sessions (telegram_group_id, lesson_id)
             WHERE status = 'open'",
        );
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS training_sessions_one_open_per_group_lesson_unique');
        DB::statement('DROP INDEX IF EXISTS exam_sessions_one_open_per_group_unique');
    }
};
