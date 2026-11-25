<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The daily round of a cup (docs/33 §2.5, §2.6; K5): `cup_round_attempts` (one rated attempt of a participant per day - UNIQUE
 * `(cup_day_id, participant_id)`) and `cup_round_answers` (one answer per task, a repeated request_id answers the stored result).
 *
 * `cup_id` and `learner_id` of an attempt are COPIES without a foreign key: the rows go with the participant (indexed cascade
 * `participant_id`) and the day (unique `(cup_day_id, …)`); a key on them would need two more indexes on the biggest table of the cup
 * only to make a cascade that already happens. The scheduler finds the abandoned attempts through a PARTIAL index on `expires_at` of the
 * active ones: an answer changes `state` and the counters (no index), so its update stays HOT (fillfactor 80: the state grows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cup_round_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cup_id');
            $table->foreignId('cup_day_id')->constrained('cup_days')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('cup_participants')->cascadeOnDelete();
            $table->unsignedBigInteger('learner_id');
            $table->jsonb('order');
            $table->jsonb('state');
            $table->smallInteger('answered')->default(0);
            $table->smallInteger('correct')->default(0);
            $table->smallInteger('points')->default(0);
            $table->string('status', 10); // active | finished
            $table->string('finish_reason', 10)->nullable(); // completed | expired
            $table->string('locale', 5);
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['cup_day_id', 'participant_id']);
            $table->index('participant_id'); // my days; the cascade of a participant
        });

        Schema::create('cup_round_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('cup_round_attempts')->cascadeOnDelete();
            $table->string('element_id', 16);
            $table->uuid('request_id');
            $table->jsonb('payload');
            $table->string('verdict', 8);
            $table->smallInteger('points');
            $table->jsonb('result');
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['attempt_id', 'element_id']);
            $table->unique(['attempt_id', 'request_id']);
        });

        DB::statement("CREATE INDEX cup_round_attempts_active_expiry_idx ON cup_round_attempts (expires_at) WHERE status = 'active'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE cup_round_attempts ADD CONSTRAINT cup_round_attempts_shape_check
                 CHECK (status IN ('active','finished')
                    AND (finish_reason IS NULL OR finish_reason IN ('completed','expired'))
                    AND ((status = 'finished') = (finish_reason IS NOT NULL))
                    AND ((status = 'finished') = (finished_at IS NOT NULL))
                    AND points BETWEEN 0 AND 20 AND correct BETWEEN 0 AND answered AND answered BETWEEN 0 AND 10
                    AND expires_at > started_at)",
            );
            DB::statement(
                "ALTER TABLE cup_round_answers ADD CONSTRAINT cup_round_answers_shape_check
                 CHECK (verdict IN ('correct','typo','wrong','skipped') AND points IN (0, 2) AND (points = 2) = (verdict = 'correct'))",
            );
            DB::statement('ALTER TABLE cup_round_attempts SET (fillfactor = 80)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_round_answers');
        Schema::dropIfExists('cup_round_attempts');
    }
};
