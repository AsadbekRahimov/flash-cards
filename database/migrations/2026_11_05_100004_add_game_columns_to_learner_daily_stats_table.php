<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The day of a learner in the game (docs/18 §12.2.7, §12.6): `game_runs_count` (completed + failed runs of the local day),
 * `game_points` (the league points of the game AFTER the daily cap) and `game_held_at` (the first finished run of the day: the
 * day is held by the game for the streak). Written in the transaction that finishes a run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learner_daily_stats', function (Blueprint $table): void {
            $table->smallInteger('game_runs_count')->default(0);
            $table->smallInteger('game_points')->default(0);
            $table->timestamp('game_held_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learner_daily_stats', function (Blueprint $table): void {
            $table->dropColumn(['game_runs_count', 'game_points', 'game_held_at']);
        });
    }
};
