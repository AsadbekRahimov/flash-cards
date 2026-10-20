<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friend challenges (docs/13 §1.5, §7.5, task 2C.5): "who repeats more in N days". The period is a
 * range of `learner_daily_stats.date` values (`starts_on`..`ends_on`, inclusive) fixed at acceptance
 * in the CHALLENGER's time zone; `ends_at` is the instant that zone's `ends_on` is over. The result
 * (`scores`, `winner_learner_id`; a draw = no winner) is computed from the daily stats at closing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenger_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('opponent_id')->constrained('learners')->cascadeOnDelete();
            $table->string('metric', 12); // reviews | new_words
            $table->unsignedSmallInteger('days');
            // pending | active | finished | declined | expired (never accepted) | canceled (friendship ended)
            $table->string('status', 10);
            // `<lower id>:<higher id>` while the challenge is open (pending / active), NULL once final: the unique
            // index makes "one open challenge per pair" a database fact (NULLs never collide).
            $table->string('open_pair', 40)->nullable()->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignId('winner_learner_id')->nullable()->constrained('learners')->nullOnDelete();
            // `{challenger: int, opponent: int}` — filled when the challenge is finished.
            $table->jsonb('scores')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The scheduler scans open challenges by status and the end of the period.
            $table->index(['status', 'ends_at']);
            $table->index(['challenger_id', 'status']);
            $table->index(['opponent_id', 'status']);
            $table->index('winner_learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
