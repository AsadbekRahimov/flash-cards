<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard widget of the game (`GameActivityStats`: runs and distinct players since a date) and the date filter of the runs list in
 * `/admin` (`GameRunResource`) read `game_runs` by `started_at` alone; the other indexes start with `learner_id`, `status` or `course_id`.
 * `(started_at, learner_id)` serves the range and the `COUNT(DISTINCT learner_id)` from the index (docs/20 §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_runs', function (Blueprint $table): void {
            $table->index(['started_at', 'learner_id'], 'game_runs_started_at_learner_index');
        });
    }

    public function down(): void
    {
        Schema::table('game_runs', function (Blueprint $table): void {
            $table->dropIndex('game_runs_started_at_learner_index');
        });
    }
};
