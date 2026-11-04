<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The caravan of a class (docs/18 §12.2.8, G2.9): `class_caravans` and the contribution of the members, and the foreign key
 * `game_runs.class_caravan_id` -> `class_caravans` (ON DELETE SET NULL) that the first migration of the game left out.
 *
 * "One active caravan per group" is a database fact: `active_group_id` (= `telegram_group_id` while `active`, NULL after) is
 * UNIQUE. On SQLite a foreign key is added by rebuilding `game_runs` (it has no partial index, so nothing is lost).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_caravans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('active_group_id')->nullable()->unique();
            $table->string('status', 10); // active | finished | canceled
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->integer('target_stars');
            $table->integer('stars_total')->default(0);
            $table->integer('runs_total')->default(0);
            $table->jsonb('boss_snapshot_words')->nullable(); // ids of the 12 hardest words of the group, fixed at the start
            $table->timestamp('boss_unlocked_at')->nullable();
            $table->timestamp('boss_defeated_at')->nullable();
            $table->timestamp('summary_posted_at')->nullable(); // the "exactly once" marker of the weekly summary
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['status', 'ends_at']); // the scheduler
            $table->index(['telegram_group_id', 'starts_at']);
        });

        Schema::create('class_caravan_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_caravan_id')->constrained('class_caravans')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->smallInteger('runs')->default(0);
            $table->smallInteger('stars')->default(0);
            $table->integer('best_score')->default(0);
            $table->timestamp('boss_won_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['class_caravan_id', 'learner_id']);
            $table->index(['class_caravan_id', 'stars', 'runs']); // the table of contributions
        });

        Schema::table('game_runs', function (Blueprint $table): void {
            $table->foreign('class_caravan_id')->references('id')->on('class_caravans')->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE class_caravans ADD CONSTRAINT class_caravans_status_check CHECK (status IN ('active','finished','canceled'))");
            DB::statement(
                "ALTER TABLE class_caravans ADD CONSTRAINT class_caravans_active_shape_check
                 CHECK ((status = 'active') = (active_group_id IS NOT NULL))",
            );
        }
    }

    public function down(): void
    {
        Schema::table('game_runs', function (Blueprint $table): void {
            $table->dropForeign(['class_caravan_id']);
        });

        // The caravans go, so the links to them mean nothing: without this the next `up()` would meet ids with no row behind them.
        DB::table('game_runs')->whereNotNull('class_caravan_id')->update(['class_caravan_id' => null]);

        Schema::dropIfExists('class_caravan_members');
        Schema::dropIfExists('class_caravans');
    }
};
