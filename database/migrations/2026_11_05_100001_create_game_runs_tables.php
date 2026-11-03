<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The game "Caravan" (docs/18 §12.2.1, §12.2.2): `game_runs` (the whole snapshot of a run + the progress) and
 * `game_run_attempts` (the journal of accepted requests: idempotency by `(run_id, request_id)`).
 *
 * "One active run per learner", "one daily caravan per local day" and "one class-boss try per caravan and learner" are
 * database facts: `active_learner_id`, `daily_key` and `class_boss_key` are UNIQUE and nullable (NULLs never collide, on
 * PostgreSQL and SQLite alike). `class_caravan_id` has no foreign key yet: `class_caravans` comes with the migration 100005,
 * which adds it. On PostgreSQL CHECKs hold the enumerations and the shape of those columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->unsignedBigInteger('active_learner_id')->nullable()->unique();
            $table->string('kind', 12); // stop | boss | daily | class_boss
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->unsignedBigInteger('class_caravan_id')->nullable(); // FK -> class_caravans: migration 100005
            $table->string('daily_key', 32)->nullable()->unique(); // "{learner_id}:{local_date}" of a `daily` run
            $table->string('class_boss_key', 40)->nullable()->unique(); // "{class_caravan_id}:{learner_id}" of a `class_boss` run
            $table->date('local_date'); // the learner's local day at the start
            $table->string('status', 10)->default('active'); // active | finished
            $table->string('finish_reason', 12)->nullable(); // completed | failed | expired | abandoned
            $table->string('free_slot', 8)->nullable(); // daily | ticket; NULL for Pro, daily and class_boss
            $table->jsonb('snapshot'); // the whole issue (docs/18 §12.3): server-only, never in an answer
            $table->jsonb('state')->default('{}'); // the progress per element; changed only under FOR UPDATE
            $table->smallInteger('total');
            $table->smallInteger('answered')->default(0);
            $table->smallInteger('correct')->default(0);
            $table->smallInteger('typos')->default(0);
            $table->smallInteger('wrong')->default(0);
            $table->smallInteger('hints')->default(0);
            $table->smallInteger('flasks');
            $table->smallInteger('flasks_max');
            $table->smallInteger('combo_best')->default(0);
            $table->integer('score')->default(0);
            $table->smallInteger('stars')->nullable();
            $table->integer('coins_awarded')->default(0);
            $table->smallInteger('league_points')->default(0);
            $table->smallInteger('srs_pulled')->default(0);
            $table->string('locale', 5);
            $table->timestamp('expires_at');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'status', 'started_at']);
            $table->index(['learner_id', 'kind', 'local_date']); // the limit of the day, "played today"
            $table->index(['status', 'expires_at']); // the scheduler
            $table->index(['class_caravan_id', 'status']);
            $table->index(['course_id', 'kind', 'finished_at']); // the reports
        });

        Schema::create('game_run_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('game_runs')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->uuid('request_id'); // the client's key of the request
            $table->string('element_id', 16);
            $table->string('kind', 8); // answer | pair | skip | hint | booster
            $table->jsonb('payload');
            $table->string('verdict', 8)->nullable(); // correct | typo | wrong | skipped; NULL for a hint / a booster
            $table->boolean('is_first')->default(true);
            $table->jsonb('result'); // the body of the answer without `run`: a repeat returns it byte for byte
            $table->integer('time_spent_ms')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->useCurrent(); // the rows never change: no updated_at

            $table->unique(['run_id', 'request_id']);
            $table->index(['run_id', 'element_id']);
            $table->index(['learner_id', 'accepted_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE game_runs ADD CONSTRAINT game_runs_kind_check CHECK (kind IN ('stop','boss','daily','class_boss'))");
            DB::statement("ALTER TABLE game_runs ADD CONSTRAINT game_runs_status_check CHECK (status IN ('active','finished'))");
            DB::statement(
                "ALTER TABLE game_runs ADD CONSTRAINT game_runs_active_shape_check
                 CHECK ((status = 'active') = (active_learner_id IS NOT NULL))",
            );
            DB::statement(
                'ALTER TABLE game_runs ADD CONSTRAINT game_runs_active_owner_check
                 CHECK (active_learner_id IS NULL OR active_learner_id = learner_id)',
            );
            DB::statement(
                "ALTER TABLE game_runs ADD CONSTRAINT game_runs_daily_shape_check
                 CHECK ((kind = 'daily') = (daily_key IS NOT NULL))",
            );
            DB::statement(
                "ALTER TABLE game_runs ADD CONSTRAINT game_runs_class_boss_shape_check
                 CHECK ((kind = 'class_boss') = (class_boss_key IS NOT NULL))",
            );
            DB::statement('ALTER TABLE game_runs ADD CONSTRAINT game_runs_flasks_check CHECK (flasks BETWEEN 0 AND flasks_max)');
            DB::statement("ALTER TABLE game_run_attempts ADD CONSTRAINT game_run_attempts_kind_check CHECK (kind IN ('answer','pair','skip','hint','booster'))");
            DB::statement("ALTER TABLE game_run_attempts ADD CONSTRAINT game_run_attempts_verdict_check CHECK (verdict IS NULL OR verdict IN ('correct','typo','wrong','skipped'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_run_attempts');
        Schema::dropIfExists('game_runs');
    }
};
