<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_question_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->unsignedSmallInteger('question_index');
            $table->timestamp('served_at');
            $table->timestamp('answered_at')->nullable();

            $table->unique(
                ['exam_session_id', 'student_id', 'question_index'],
                'exam_question_attempts_session_student_index_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_question_attempts');
    }
};
