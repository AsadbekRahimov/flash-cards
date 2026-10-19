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
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();
            $table->smallInteger('repetitions')->default(0);
            $table->smallInteger('interval_days')->default(0);
            $table->decimal('easiness_factor', 4, 2)->default(2.50);
            $table->smallInteger('last_quality')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamp('next_review_at');
            $table->boolean('is_hard')->default(false);
            // Mode of the training session that first served the word (`group_lesson` / `lesson` /
            // `daily_review`): words introduced by a teacher's group lesson are outside the Free
            // quota (docs/12 §5.3). The default covers rows not created by CardPicker (seeders).
            $table->string('introduced_mode', 16)->default('lesson');
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['learner_id', 'word_id']);
            $table->index(['learner_id', 'next_review_at']);
            // Daily new-word quota counts rows created in the learner's local day.
            $table->index(['learner_id', 'created_at']);
        });

        DB::statement(
            'CREATE INDEX word_repetitions_learner_is_hard_idx
             ON word_repetitions (learner_id)
             WHERE is_hard = true',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('word_repetitions');
    }
};
