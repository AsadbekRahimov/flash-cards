<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 1-on-1 battle (docs/16 §2.7): `battles` (the match: status, the snapshot of 5 rounds, the clocks),
 * `battle_participants` (exactly two rows per match) and `battle_answers` (one final answer per round and player).
 *
 * "One active battle per learner" is a database fact: `battle_participants.active_learner_id` (= `learner_id`
 * while the player is busy with the match: the challenger from the invitation, the opponent from the
 * acceptance; NULL after any end) is UNIQUE. On PostgreSQL CHECKs hold the enumerations and the shapes.
 *
 * The tables are created together with the solo practice so that the battle (docs/16 §8) starts on a ready schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('battles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('challenger_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('opponent_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('friendship_id')->nullable()->constrained('friendships')->nullOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('mode', 12); // pairs | collocation | sentence | dictation
            $table->string('status', 10); // invited -> room -> countdown -> playing -> finished | closed
            $table->string('end_reason', 16)->nullable();
            $table->jsonb('snapshot'); // the 5 rounds (docs/16 §2.8), shared by both players
            $table->smallInteger('current_round')->default(0);
            $table->string('phase', 10)->nullable(); // answering | reveal
            $table->timestamp('round_deadline_at')->nullable();
            $table->timestamp('phase_ends_at')->nullable();
            $table->timestamp('invite_expires_at');
            $table->timestamp('room_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('winner_learner_id')->nullable()->constrained('learners')->nullOnDelete();
            $table->foreignId('rematch_of_id')->nullable()->constrained('battles')->nullOnDelete();
            $table->integer('state_version')->default(0); // grows with every change the players can see; the presence does not change it
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'invite_expires_at']);
            $table->index(['challenger_id', 'status']);
            $table->index(['opponent_id', 'status']);
            $table->index('rematch_of_id');
            $table->index('winner_learner_id');
            $table->index('friendship_id');
        });

        Schema::create('battle_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('battle_id')->constrained('battles')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('role', 10); // challenger | opponent
            $table->unsignedBigInteger('active_learner_id')->nullable()->unique();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('surrendered_at')->nullable();
            $table->smallInteger('score')->default(0);
            $table->string('locale', 5);
            $table->timestamps();

            $table->unique(['battle_id', 'learner_id']);
            $table->index(['learner_id', 'battle_id']);
        });

        Schema::create('battle_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('battle_id')->constrained('battles')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->smallInteger('round');
            $table->uuid('request_id');
            $table->jsonb('payload');
            $table->string('verdict', 8); // correct | typo | wrong | skipped | timeout
            $table->smallInteger('points');
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['battle_id', 'round', 'learner_id']); // one final answer per round
            $table->unique(['battle_id', 'learner_id', 'request_id']); // a repeated request
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE battles ADD CONSTRAINT battles_distinct_players_check CHECK (challenger_id <> opponent_id)');
            DB::statement('ALTER TABLE battles ADD CONSTRAINT battles_round_check CHECK (current_round BETWEEN 0 AND 5)');
            DB::statement("ALTER TABLE battles ADD CONSTRAINT battles_status_check CHECK (status IN ('invited','room','countdown','playing','finished','closed'))");
            DB::statement("ALTER TABLE battles ADD CONSTRAINT battles_phase_shape_check CHECK ((status = 'playing') = (phase IS NOT NULL))");
            DB::statement(
                'ALTER TABLE battle_participants ADD CONSTRAINT battle_participants_active_owner_check
                 CHECK (active_learner_id IS NULL OR active_learner_id = learner_id)',
            );
            DB::statement('ALTER TABLE battle_answers ADD CONSTRAINT battle_answers_points_check CHECK (points BETWEEN 0 AND 100)');
            DB::statement('ALTER TABLE battle_answers ADD CONSTRAINT battle_answers_round_check CHECK (round BETWEEN 1 AND 5)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('battle_answers');
        Schema::dropIfExists('battle_participants');
        Schema::dropIfExists('battles');
    }
};
