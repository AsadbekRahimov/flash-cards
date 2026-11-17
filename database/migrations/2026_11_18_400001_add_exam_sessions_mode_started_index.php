<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exams list of `/admin` filtered by the mode (group exams by default; the quizzes of the week of every learner are the bulk of
 * the table): `mode = ? ORDER BY started_at DESC, id DESC` (docs/20 §1.5: the sort key plus the primary key Filament adds).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->index(['mode', 'started_at', 'id'], 'exam_sessions_mode_started_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropIndex('exam_sessions_mode_started_id_idx');
        });
    }
};
