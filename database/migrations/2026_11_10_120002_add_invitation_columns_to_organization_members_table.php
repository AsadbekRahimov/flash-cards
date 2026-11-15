<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent of the learner to a membership added by the center's staff (audit 2026-11-10 M2): "add by @username" now
 * creates a row with `status = invited` (no seat, no Pro, invisible to the teacher's lists) and the bot asks the learner;
 * the row becomes `active` on "Join", `declined` on "Decline", and is deleted when the staff revokes it. `invited_at` /
 * `invited_by_user_id` carry the offer; `joined_at` keeps its NOT NULL and holds the time of the offer until the join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->timestamp('invited_at')->nullable()->after('joined_at');
            $table->foreignId('invited_by_user_id')->nullable()->after('invited_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organization_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by_user_id');
            $table->dropColumn('invited_at');
        });
    }
};
