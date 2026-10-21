<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personal mock exams (docs/13 §1.6, task 2D.4): `exam_sessions` gets a `mode`
 * (`group_exam` / `personal_mock`) the way `training_sessions` did in Phase 1.
 * A `personal_mock` session belongs to a learner and a course and has no group,
 * lesson or teacher, so those three columns become nullable; the shape of each
 * mode is enforced by a PostgreSQL CHECK (the service duplicates it for sqlite).
 *
 * At most one open mock per learner — a partial unique index like
 * `training_sessions_one_open_daily_per_learner`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite rebuilds the table on a column change and re-creates its indexes WITHOUT the
        // partial predicate; a full unique on telegram_group_id would fail on real data (open +
        // closed exams of a group) — drop the partial index first, put it back after (pgsql keeps it).
        $this->dropGroupIndexOnSqlite();

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('telegram_group_id')->nullable()->change();
            $table->unsignedBigInteger('lesson_id')->nullable()->change();
            $table->unsignedBigInteger('started_by_user_id')->nullable()->change();

            $table->foreignId('learner_id')->nullable()->after('started_by_user_id')->constrained('learners')->cascadeOnDelete();
            $table->string('mode', 16)->default('group_exam')->after('learner_id');
            // A mock of a deleted course is meaningless (courses are archived, not deleted, in practice).
            $table->foreignId('course_id')->nullable()->after('mode')->constrained('courses')->cascadeOnDelete();

            $table->index(['learner_id', 'status', 'started_at'], 'exam_sessions_learner_status_started_index');
            // Percentile of a mock: closed mocks of the course over the last 90 days.
            $table->index(['course_id', 'mode', 'closed_at'], 'exam_sessions_course_mode_closed_index');
        });

        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $this->restoreGroupIndexOnSqlite();

        DB::statement(
            "CREATE UNIQUE INDEX exam_sessions_one_open_mock_per_learner
             ON exam_sessions (learner_id)
             WHERE status = 'open' AND mode = 'personal_mock'",
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE exam_sessions
                 ADD CONSTRAINT exam_sessions_mode_shape_check CHECK (
                     (mode = 'group_exam' AND telegram_group_id IS NOT NULL AND lesson_id IS NOT NULL AND started_by_user_id IS NOT NULL)
                     OR (mode = 'personal_mock' AND learner_id IS NOT NULL AND course_id IS NOT NULL)
                 )",
            );
        }
    }

    public function down(): void
    {
        // Personal mocks cannot exist without the columns below.
        DB::table('exam_sessions')->where('mode', 'personal_mock')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE exam_sessions DROP CONSTRAINT IF EXISTS exam_sessions_mode_shape_check');
        }

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS exam_sessions_one_open_mock_per_learner');
        }

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropIndex('exam_sessions_learner_status_started_index');
            $table->dropIndex('exam_sessions_course_mode_closed_index');
        });

        $this->dropGroupIndexOnSqlite();

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropForeign(['learner_id']);
            $table->dropForeign(['course_id']);
            $table->unsignedBigInteger('telegram_group_id')->nullable(false)->change();
            $table->unsignedBigInteger('lesson_id')->nullable(false)->change();
            $table->unsignedBigInteger('started_by_user_id')->nullable(false)->change();
        });

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropColumn(['learner_id', 'mode', 'course_id']);
        });

        $this->restoreGroupIndexOnSqlite();
    }

    private function dropGroupIndexOnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS exam_sessions_one_open_per_group_unique');
        }
    }

    private function restoreGroupIndexOnSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                "CREATE UNIQUE INDEX exam_sessions_one_open_per_group_unique
                 ON exam_sessions (telegram_group_id)
                 WHERE status = 'open'",
            );
        }
    }
};
