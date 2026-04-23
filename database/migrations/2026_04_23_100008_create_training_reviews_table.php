<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('words')->restrictOnDelete();
            $table->smallInteger('quality');
            $table->unsignedInteger('time_spent_ms');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['student_id', 'created_at']);
            $table->index(['word_id', 'quality']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_reviews');
    }
};
