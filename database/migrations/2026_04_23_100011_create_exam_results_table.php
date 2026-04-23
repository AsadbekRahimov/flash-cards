<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_session_id')->constrained('exam_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->integer('total_score');
            $table->integer('correct_count');
            $table->integer('total_count');
            $table->integer('rank');
            $table->timestamps();

            $table->index(['exam_session_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
