<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Pairs against the clock" (docs/16 §2.5, §2.10, §5.5a): the fifth mode `pairs_timed`.
 *
 * `practice_sessions.deadline_at` (NULL until `POST /start`; milliseconds - a mismatch moves it by 2 s, the grace is 1.5 s) and
 * `score` (the pairs matched; written only by the answer under the lock of the session). `practice_records`: the best score of a
 * learner per mode, one row per `(learner_id, mode)`; written in the transaction that finishes a session, AFTER the session row.
 * On PostgreSQL the CHECK of the mode learns the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_sessions', function (Blueprint $table): void {
            $table->timestamp('deadline_at', 3)->nullable();
            $table->smallInteger('score')->default(0);
        });

        Schema::create('practice_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('mode', 12);
            $table->smallInteger('best_score');
            $table->foreignId('best_session_id')->nullable()->constrained('practice_sessions')->nullOnDelete();
            $table->timestamp('achieved_at');
            $table->timestamps();

            $table->unique(['learner_id', 'mode']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE practice_sessions DROP CONSTRAINT IF EXISTS practice_sessions_mode_check');
            DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation','pairs_timed'))");
            DB::statement("ALTER TABLE practice_records ADD CONSTRAINT practice_records_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation','pairs_timed'))");
        }
    }

    public function down(): void
    {
        // The narrower CHECK cannot hold these rows, and a rollback must not lose them silently.
        if (DB::table('practice_sessions')->where('mode', 'pairs_timed')->exists()) {
            throw new RuntimeException('Cannot roll back: practice_sessions has rows of the mode pairs_timed. Delete them first.');
        }

        Schema::dropIfExists('practice_records');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE practice_sessions DROP CONSTRAINT IF EXISTS practice_sessions_mode_check');
            DB::statement("ALTER TABLE practice_sessions ADD CONSTRAINT practice_sessions_mode_check CHECK (mode IN ('pairs','collocation','sentence','dictation'))");
        }

        Schema::table('practice_sessions', function (Blueprint $table): void {
            $table->dropColumn(['deadline_at', 'score']);
        });
    }
};
