<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The seat lookup of a weekly placement (LeagueService::groupWithSeat, docs/32 §5.1, S8) filters by week, category and tier - in a
 * v1 week too (every v1 group is `free`). One index serves it: `(league_week_id, category, tier)`.
 *
 *   - `(league_week_id, tier, members_count)` (2026_10_22_300001) goes: no query filters by week and tier without the category any more;
 *   - `(league_week_id, category, tier, members_count)` (2026_11_25_300005) goes too: `members_count < capacity` compares two columns,
 *     so the index can only filter by it, never seek - and `members_count` changes at every placement, so with it in an index every
 *     seat taken was a non-HOT update that wrote a new entry into every index of the row. Without it the increment touches no indexed
 *     column (`updated_at` neither) and stays HOT.
 *
 * The prefix `league_week_id` keeps serving the foreign key (`ON DELETE CASCADE` of a week) and the close (`closeRemainingGroups`: the
 * unstamped groups of a week by id). The table has a few dozen rows a week: plain statements, no CONCURRENTLY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_groups', function (Blueprint $table): void {
            $table->index(['league_week_id', 'category', 'tier'], 'league_groups_week_category_tier_idx');
        });

        Schema::table('league_groups', function (Blueprint $table): void {
            $table->dropIndex(['league_week_id', 'tier', 'members_count']);
            $table->dropIndex(['league_week_id', 'category', 'tier', 'members_count']);
        });
    }

    public function down(): void
    {
        Schema::table('league_groups', function (Blueprint $table): void {
            $table->index(['league_week_id', 'tier', 'members_count']);
            $table->index(['league_week_id', 'category', 'tier', 'members_count']);
        });

        Schema::table('league_groups', function (Blueprint $table): void {
            $table->dropIndex('league_groups_week_category_tier_idx');
        });
    }
};
