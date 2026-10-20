<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Streak freeze (docs/13 §1.5, task 2C.2): the inventory on `learners` and the fact
 * "this local day was held by a freeze" on `learner_daily_stats`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->smallInteger('streak_freezes')->default(0)->after('streak_last_date');
            $table->date('streak_freezes_refilled_on')->nullable()->after('streak_freezes');
        });

        Schema::table('learner_daily_stats', function (Blueprint $table): void {
            $table->timestamp('frozen_at')->nullable()->after('goal_met_at');
        });
    }

    public function down(): void
    {
        Schema::table('learner_daily_stats', function (Blueprint $table): void {
            $table->dropColumn('frozen_at');
        });

        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn(['streak_freezes', 'streak_freezes_refilled_on']);
        });
    }
};
