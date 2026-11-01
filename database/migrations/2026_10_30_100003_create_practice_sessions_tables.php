<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The solo practice (docs/16 §2.5, §2.6): `practice_sessions` (the full snapshot of the issue + the progress)
 * and `practice_attempts` (the journal of accepted requests: idempotency by `(session_id, request_id)`).
 *
 * "One active session per learner" is a database fact: `active_learner_id` (= `learner_id` while the session is
 * `active`, NULL after) is UNIQUE, and NULLs never collide - the same on PostgreSQL and SQLite, like
 * `challenges.open_pair`. On PostgreSQL CHECKs also hold the enumerations and the shape of that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->unsignedBigInteger('active_learner_id')->nullable()->unique();
            $table->string('mode', 12); // pairs | collocation | sentence | dictation
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('source', 16); // hub | after_training | retry | deeplink
            $table->foreignId('retry_of_session_id')->nullable()->constrained('practice_sessions')->nullOnDelete();
            $table->string('status', 10)->default('active'); // active | finished
            $table->string('finish_reason', 12)->nullable(); // completed | expired | replaced
            $table->jsonb('snapshot'); // the whole issue (docs/16 §2.8): server-only, never in an answer
            $table->jsonb('state')->default('{}'); // the progress per element; changed only under FOR UPDATE
            $table->smallInteger('total');
            $table->smallInteger('answered')->default(0);
            $table->smallInteger('correct_first')->default(0);
            $table->smallInteger('hints')->default(0);
            $table->smallInteger('mismatches')->default(0);
            $table->string('locale', 5);
            $table->timestamp('expires_at');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'status', 'started_at']);
            $table->index(['status', 'expires_at']); // the scheduler
            $table->index('retry_of_session_id');
            $table->index(['course_id', 'mode', 'finished_at']); // the reports of the pilot
        });

        Schema::create('practice_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('practice_sessions')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->uuid('request_id'); // the client's key of the request
            $table->string('element_id', 16);
            $table->string('kind', 8); // answer | pair | skip | replace
            $table->jsonb('payload');
            $table->string('verdict', 8)->nullable(); // correct | typo | wrong | skipped; NULL for a replace
            $table->boolean('is_first')->default(true);
            $table->jsonb('result'); // the body of the answer without `session`: a repeat returns it byte for byte
            $table->integer('time_spent_ms')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->useCurrent(); // the rows never change: no updated_at

            $table->unique(['session_id', 'request_id']);
            $table->index(['session_id', 'element_id']);
            $table->index(['learner_id', 'accepted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation'))");
            DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_status_check CHECK (status IN ('active','finished'))");
            DB::statement(
                "ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_active_shape_check
                 CHECK ((status = 'active') = (active_learner_id IS NOT NULL))",
            );
            DB::statement(
                'ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_active_owner_check
                 CHECK (active_learner_id IS NULL OR active_learner_id = learner_id)',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempts');
        Schema::dropIfExists('practice_sessions');
    }
};
