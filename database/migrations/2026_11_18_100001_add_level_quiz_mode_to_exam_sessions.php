<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The quiz of the week (docs/13 §8.5): a third `mode` of `exam_sessions`, `level_quiz`, owned by a learner and a course like a
 * `personal_mock` (no group, lesson or teacher). No column is added (`mode` is `varchar(16)`): on PostgreSQL the CHECK of the shape
 * of each mode learns the new mode, and at most ONE open quiz per learner is a partial unique index (as for the mock).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX exam_sessions_one_open_quiz_per_learner
             ON exam_sessions (learner_id)
             WHERE status = 'open' AND mode = 'level_quiz'",
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exam_sessions DROP CONSTRAINT IF EXISTS exam_sessions_mode_shape_check');
            DB::statement(
                "ALTER TABLE exam_sessions
                 ADD CONSTRAINT exam_sessions_mode_shape_check CHECK (
                     (mode = 'group_exam' AND telegram_group_id IS NOT NULL AND lesson_id IS NOT NULL AND started_by_user_id IS NOT NULL)
                     OR (mode IN ('personal_mock', 'level_quiz') AND learner_id IS NOT NULL AND course_id IS NOT NULL)
                 )",
            );
        }
    }

    public function down(): void
    {
        // The narrower CHECK cannot hold these rows, and a rollback must not lose them (and, by the cascade, their answers and
        // results) silently.
        if (DB::table('exam_sessions')->where('mode', 'level_quiz')->exists()) {
            throw new RuntimeException('Cannot roll back: exam_sessions has rows of the mode level_quiz. Delete them first.');
        }

        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS exam_sessions_one_open_quiz_per_learner');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exam_sessions DROP CONSTRAINT IF EXISTS exam_sessions_mode_shape_check');
            DB::statement(
                "ALTER TABLE exam_sessions
                 ADD CONSTRAINT exam_sessions_mode_shape_check CHECK (
                     (mode = 'group_exam' AND telegram_group_id IS NOT NULL AND lesson_id IS NOT NULL AND started_by_user_id IS NOT NULL)
                     OR (mode = 'personal_mock' AND learner_id IS NOT NULL AND course_id IS NOT NULL)
                 )",
            );
        }
    }
};
