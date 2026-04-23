<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_repetitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();
            $table->smallInteger('repetitions')->default(0);
            $table->smallInteger('interval_days')->default(0);
            $table->decimal('easiness_factor', 4, 2)->default(2.50);
            $table->smallInteger('last_quality')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('next_review_at');
            $table->boolean('is_hard')->default(false);
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['student_id', 'word_id']);
            $table->index(['student_id', 'next_review_at']);
        });

        DB::statement(
            'CREATE INDEX word_repetitions_student_is_hard_idx
             ON word_repetitions (student_id)
             WHERE is_hard = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('word_repetitions');
    }
};
