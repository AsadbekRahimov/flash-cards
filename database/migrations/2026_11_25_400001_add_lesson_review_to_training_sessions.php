<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The fourth personal mode of `training_sessions`, `lesson_review` - the review of a lesson just studied (docs/04 A.3, docs/06 §15).
 *
 * No column: the state of the round lives in `meta` (added with `hard_review`, 2026_11_18_300001), `mode` is a plain varchar(16)
 * without a CHECK, and the shape invariant `mode <> 'group_lesson' => learner_id` already holds. Only the partial unique index
 * `training_sessions_one_open_lesson_review_per_learner (learner_id) WHERE status = 'open' AND mode = 'lesson_review'`: at most one
 * open review round per learner, whatever bypasses the learner lock of LessonReviewService (two tabs, two lessons).
 */
return new class extends Migration
{
    private const INDEX = 'training_sessions_one_open_lesson_review_per_learner';

    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX." ON training_sessions (learner_id) WHERE status = 'open' AND mode = 'lesson_review'",
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
