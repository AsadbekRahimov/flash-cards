<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leagues (docs/13 §1.5, task 2C.3): one row per calendar week (`league_weeks`, Monday in the
 * league time zone), groups of up to `capacity` learners per tier (`league_groups`) and the
 * membership of a learner in one group of the week (`league_members`, unique per week). Points
 * are not stored until the week is closed: `final_points` / `final_rank` / `result` are written
 * once by `LeagueService::closeWeek`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_weeks', function (Blueprint $table): void {
            $table->id();
            $table->date('starts_on')->unique();
            $table->date('ends_on');
            $table->string('status', 10)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('league_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_week_id')->constrained('league_weeks')->cascadeOnDelete();
            $table->smallInteger('tier');
            $table->smallInteger('capacity')->default(30);
            $table->smallInteger('members_count')->default(0);
            $table->timestamps();

            $table->index(['league_week_id', 'tier', 'members_count']);
        });

        Schema::create('league_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_group_id')->constrained('league_groups')->cascadeOnDelete();
            // Denormalised from the group: the uniqueness "one group per learner and week" needs it.
            $table->foreignId('league_week_id')->constrained('league_weeks')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->smallInteger('tier_before');
            $table->integer('final_points')->nullable();
            $table->smallInteger('final_rank')->nullable();
            $table->string('result', 10)->nullable();
            $table->timestamps();

            $table->unique(['league_week_id', 'learner_id']);
            $table->index('league_group_id');
            // "Where did the learner end up last time" (the tier of the next placement).
            $table->index(['learner_id', 'league_week_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_members');
        Schema::dropIfExists('league_groups');
        Schema::dropIfExists('league_weeks');
    }
};
