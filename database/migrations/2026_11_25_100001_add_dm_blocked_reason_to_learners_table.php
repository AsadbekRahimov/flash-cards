<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/34 §4в п. 3, B2 - why the private chat of a learner is closed, next to `dm_blocked_at` (the name of that column stays: the
 * index of the reminders and a dozen queries read it). Values: `bot_blocked`, `not_started`, `user_deactivated`, `chat_not_found`,
 * `forbidden` (Telegram's answer to a send) and `kicked` (`my_chat_member` of the private chat). Written only by
 * App\Domain\Telegram\Services\DmReachability.
 *
 * The partial index serves the counter of the dashboard of `/admin` (all closed, closed in the last 7 days) and the filter
 * «Бот недоступен» of the learners' list (docs/34 §4в п. 4, docs/20 §1.5): the closed DMs are a small part of the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('dm_blocked_reason', 24)->nullable()->after('dm_blocked_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX learners_dm_blocked_at_idx ON learners (dm_blocked_at) WHERE dm_blocked_at IS NOT NULL');
        } else {
            Schema::table('learners', function (Blueprint $table): void {
                $table->index('dm_blocked_at', 'learners_dm_blocked_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropIndex('learners_dm_blocked_at_idx');
            $table->dropColumn('dm_blocked_reason');
        });
    }
};
