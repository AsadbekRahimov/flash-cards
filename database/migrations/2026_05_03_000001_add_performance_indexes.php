<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->index(['student_id', 'is_hard', 'next_review_at'], 'word_repetitions_student_hard_due_idx');
            $table->index(['next_review_at', 'student_id'], 'word_repetitions_due_students_idx');
        });

        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->index(['telegram_group_id', 'lesson_id', 'status'], 'training_sessions_group_lesson_status_idx');
            $table->index(['telegram_group_id', 'status', 'started_at'], 'training_sessions_group_status_started_idx');
        });

        Schema::table('training_reviews', function (Blueprint $table): void {
            $table->index(['created_at', 'student_id'], 'training_reviews_created_student_idx');
            $table->index(['training_session_id', 'student_id'], 'training_reviews_session_student_idx');
        });

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->index(['status', 'ends_at'], 'exam_sessions_status_ends_idx');
            $table->index(['telegram_group_id', 'status', 'started_at'], 'exam_sessions_group_status_started_idx');
        });

        Schema::table('exam_answers', function (Blueprint $table): void {
            $table->unique(['exam_session_id', 'student_id', 'word_id'], 'exam_answers_session_student_word_unique');
            $table->index(['answered_at', 'student_id'], 'exam_answers_answered_student_idx');
        });

        Schema::table('exam_results', function (Blueprint $table): void {
            $table->unique(['exam_session_id', 'student_id'], 'exam_results_session_student_unique');
        });

        Schema::table('notifications_log', function (Blueprint $table): void {
            $table->index(['type', 'sent_at'], 'notifications_log_type_sent_idx');
        });
    }

    public function down(): void
    {
        Schema::table('notifications_log', function (Blueprint $table): void {
            $table->dropIndex('notifications_log_type_sent_idx');
        });

        Schema::table('exam_results', function (Blueprint $table): void {
            $table->dropUnique('exam_results_session_student_unique');
        });

        Schema::table('exam_answers', function (Blueprint $table): void {
            $table->dropIndex('exam_answers_answered_student_idx');
            $table->dropUnique('exam_answers_session_student_word_unique');
        });

        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropIndex('exam_sessions_group_status_started_idx');
            $table->dropIndex('exam_sessions_status_ends_idx');
        });

        Schema::table('training_reviews', function (Blueprint $table): void {
            $table->dropIndex('training_reviews_session_student_idx');
            $table->dropIndex('training_reviews_created_student_idx');
        });

        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropIndex('training_sessions_group_status_started_idx');
            $table->dropIndex('training_sessions_group_lesson_status_idx');
        });

        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->dropIndex('word_repetitions_due_students_idx');
            $table->dropIndex('word_repetitions_student_hard_due_idx');
        });
    }
};
