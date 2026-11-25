<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/34 §2б.2, P2 - the daily goal a learner had chosen before App\Domain\Learners\Services\DailyGoalLimiter lowered it to the limit
 * of Free (Pro ended); it comes back when Pro does. NULL = the goal was not lowered (or the learner chose one by hand since).
 * No index: only the learner's own row reads it, and the nightly `learners:enforce-goal-limits` selects by `daily_goal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->smallInteger('daily_goal_before_limit')->nullable()->after('daily_goal');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn('daily_goal_before_limit');
        });
    }
};
