<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The third personal mode of `training_sessions`, `hard_review` - the round of hard words (docs/06 §14).
 *
 *   - `meta` jsonb `{"hard_words": [word ids]}`: the set of the round, fixed when it is opened (`TrainingScope::hard()` reads it, so
 *     "N of 10" has a stable N). Empty `{}` for every other mode. No CHECK on `mode` exists (it is a plain varchar(16)), the shape
 *     invariant `mode <> 'group_lesson' => learner_id` already holds for the new mode;
 *   - `training_sessions_one_open_hard_per_learner (learner_id) WHERE status = 'open' AND mode = 'hard_review'`: at most one open
 *     round of hard words per learner, whatever bypasses the learner lock of HardRoundService (as for the daily and the lesson mode,
 *     2026_07_13_000002). A daily round and a hard round are open at the same time - they are different modes.
 */
return new class extends Migration
{
    private const INDEX = 'training_sessions_one_open_hard_per_learner';

    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->jsonb('meta')->default('{}');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX." ON training_sessions (learner_id) WHERE status = 'open' AND mode = 'hard_review'",
            );
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }

        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
