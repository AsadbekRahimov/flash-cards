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

        // Group sessions only: personal sessions have NULL group, and NULLs never conflict.
        DB::statement(
            "CREATE UNIQUE INDEX training_sessions_one_open_per_group_lesson_unique
             ON training_sessions (telegram_group_id, lesson_id)
             WHERE status = 'open'",
        );

        // One open personal session per learner and scope (docs/12 §1.4).
        DB::statement(
            "CREATE UNIQUE INDEX training_sessions_one_open_daily_per_learner
             ON training_sessions (learner_id)
             WHERE status = 'open' AND mode = 'daily_review'",
        );

        DB::statement(
            "CREATE UNIQUE INDEX training_sessions_one_open_lesson_per_learner
             ON training_sessions (learner_id, lesson_id)
             WHERE status = 'open' AND mode = 'lesson'",
        );

        // Shape invariant is duplicated in TrainingSessionService for other drivers.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE training_sessions
                 ADD CONSTRAINT training_sessions_mode_shape_check CHECK (
                     (mode = 'group_lesson' AND telegram_group_id IS NOT NULL AND lesson_id IS NOT NULL)
                     OR (mode <> 'group_lesson' AND learner_id IS NOT NULL)
                 )",
            );
        }
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE training_sessions DROP CONSTRAINT IF EXISTS training_sessions_mode_shape_check');
        }

        DB::statement('DROP INDEX IF EXISTS training_sessions_one_open_lesson_per_learner');
        DB::statement('DROP INDEX IF EXISTS training_sessions_one_open_daily_per_learner');
        DB::statement('DROP INDEX IF EXISTS training_sessions_one_open_per_group_lesson_unique');
        DB::statement('DROP INDEX IF EXISTS exam_sessions_one_open_per_group_unique');
    }
};
