<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Results of the quiz of the week (docs/03 §2.65, docs/13 §8.5): one row per finished quiz that had an answer. `week_start` is the
 * Monday of the ISO week in the learner's zone when the quiz was opened; `scored` marks the learner's ONE counted quiz of that week
 * for the course - a partial unique index, so the rule is the database's and not only the code's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_quiz_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->date('week_start');
            $table->boolean('scored');
            $table->unsignedSmallInteger('correct');
            $table->unsignedSmallInteger('total');
            $table->timestamp('finished_at');
            $table->timestamp('created_at')->nullable();

            $table->unique('exam_session_id');
            $table->index(['learner_id', 'course_id', 'week_start'], 'level_quiz_results_learner_course_week_index');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX level_quiz_results_one_scored_per_week
                 ON level_quiz_results (learner_id, course_id, week_start)
                 WHERE scored',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('level_quiz_results');
    }
};
