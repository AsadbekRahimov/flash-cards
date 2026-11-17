<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `training_sessions.completed_at` (docs/06 §13.2, docs/12 §7.6): the moment the server had no more cards for a personal session
 * that had grades — the round (`sprint_complete`, `nothing_due`, `new_quota_exhausted`, `session_cap`) or the lesson
 * (`lesson_complete`) was played to its end. Set once by TrainingFlowService on the step that answers `card: null`, whether or
 * not the Mini App ever calls `/finish` (a learner who closes the app on the verdict of the last card does not). Read by
 * TrainingSessionService: a completed `daily_review` session is closed as `finished` and replaced on the next open instead of
 * being handed back exhausted, and the scheduler closes a completed session as `finished`, not `expired`, so the retention
 * report does not count a round played to the end as abandoned. Null: the session still has cards (or had none at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->timestamp('completed_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropColumn('completed_at');
        });
    }
};
