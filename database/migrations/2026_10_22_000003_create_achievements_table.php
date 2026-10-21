<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achievements (docs/13 §1.5, task 2C.4): one row per (learner, key) — the unique index is what
 * makes a key unlock exactly once, also when two requests race (`INSERT … ON CONFLICT DO NOTHING`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('key', 32);
            $table->timestamp('unlocked_at');
            $table->timestamp('shared_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
