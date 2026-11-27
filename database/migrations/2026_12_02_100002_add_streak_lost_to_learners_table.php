<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/40 §2.5 - "the streak was lost" of the Today screen: how long the streak burnt at its last loss was (`streak_lost_days`) and
 * the learner's local day the loss was noticed (`streak_lost_on`). Written by StreakService::burn() in its conditional UPDATE and
 * cleared by project() once the new streak gets its first day; NULL = nothing to show. No index: only the learner's own row reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->smallInteger('streak_lost_days')->nullable()->after('streak_last_date');
            $table->date('streak_lost_on')->nullable()->after('streak_lost_days');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn(['streak_lost_days', 'streak_lost_on']);
        });
    }
};
