<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Friends (docs/13 §1.5, task 2C.5): ONE row per pair of learners. The pair is normalised
 * (`learner_id` < `friend_id`), so A→B and B→A are the same row; the unique index and, on PostgreSQL,
 * a CHECK keep it so. `requested_by_learner_id` is the side that asked (for a `blocked` row: the
 * side that blocked).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('learners')->cascadeOnDelete();
            $table->string('status', 10); // pending | accepted | blocked
            $table->foreignId('requested_by_learner_id')->constrained('learners')->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'friend_id']);
            // The unique index serves lookups by `learner_id`; the other side needs its own.
            $table->index(['friend_id', 'status']);
            $table->index('requested_by_learner_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE friendships ADD CONSTRAINT friendships_pair_order_check CHECK (learner_id < friend_id)');
        }
    }

    public function down(): void
    {
        // Dropping the table drops its constraints and indexes on every driver.
        Schema::dropIfExists('friendships');
    }
};
