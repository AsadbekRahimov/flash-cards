<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('word_id')->constrained('words')->restrictOnDelete();
            $table->string('selected_translation', 500)->nullable();
            $table->boolean('is_correct');
            $table->integer('score')->default(0);
            $table->unsignedInteger('time_spent_ms');
            $table->timestamp('answered_at');

            $table->index(['exam_session_id', 'student_id']);
            $table->index(['student_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
