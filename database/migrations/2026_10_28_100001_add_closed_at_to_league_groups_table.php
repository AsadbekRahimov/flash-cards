<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The group becomes the unit of closing a league week (docs/13 §7.3, P1-2): `league_groups.closed_at` is
 * stamped when the group's final points / ranks / results were written — by the week close, group by group
 * in short transactions, or by the placement of one of its members into the next week that needed the
 * verdict early. Groups of weeks already closed by the previous (whole-week) code are stamped with the
 * week's `closed_at`, so "finalised ⇔ closed_at IS NOT NULL" holds for every row.
 *
 * The status is the literal the whole-week code wrote (`'closed'`), not the application's enum: a migration is a
 * fixed point of the schema's history and must run the same whatever the code looks like today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_groups', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('members_count');
        });

        $closedWeeks = DB::table('league_weeks')
            ->where('status', 'closed')
            ->whereNotNull('closed_at')
            ->get(['id', 'closed_at']);

        foreach ($closedWeeks as $week) {
            DB::table('league_groups')
                ->where('league_week_id', $week->id)
                ->whereNull('closed_at')
                ->update(['closed_at' => $week->closed_at]);
        }
    }

    public function down(): void
    {
        Schema::table('league_groups', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
