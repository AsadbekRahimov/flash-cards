<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress snapshot of one learner on one assignment (docs/13 §1.4), recomputed by
 * `assignments:refresh` from `word_repetitions`. `completed_at` is set once and never reset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->unsignedSmallInteger('progress_pct')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['assignment_id', 'learner_id']);
            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_results');
    }
};
