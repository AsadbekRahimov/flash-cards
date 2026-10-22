<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outbox mark for the result DMs of a finished challenge (docs/13 §7.5): set once both jobs are queued, so
 * `ChallengeService::recoverUnnotified` can re-queue the ones lost between the `finished` claim and the queue.
 * Challenges finished before this column existed count as notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->timestamp('dms_dispatched_at')->nullable()->after('finished_at');
            $table->index(['status', 'dms_dispatched_at'], 'challenges_status_dms_idx');
        });

        DB::table('challenges')
            ->where('status', 'finished')
            ->whereNull('dms_dispatched_at')
            ->update(['dms_dispatched_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropIndex('challenges_status_dms_idx');
            $table->dropColumn('dms_dispatched_at');
        });
    }
};
