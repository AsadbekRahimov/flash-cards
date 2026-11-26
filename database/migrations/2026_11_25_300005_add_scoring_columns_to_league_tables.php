<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The league tables learn the word points v2 (docs/32 §2.6, task S2): the rules version of a week and a month (written when the row
 * is created and never changed: open periods finish by their own rules), the category of a weekly group, and the tie-break facts
 * of the finals (long words, days). Every new column has a default or is nullable, so the rows written by the v1 code stay valid:
 * version 1, the groups `free`, NULL categories of the v1 month (one common table). Nothing reads them yet (S8, S9).
 *
 * The seat of a v2 group is looked up by week, category and tier: a new index `(league_week_id, category, tier, members_count)`.
 * docs/32 §2.6 has it replace `(league_week_id, tier, members_count)`; that one stays until the v2 placement (S8) filters by the
 * category - until then the v1 lookup by week and tier keeps the exact index it was written for (the table is a few dozen rows a
 * week, the second index costs nothing). S8 drops it. The month's v2 standings index is built by the next migration (CONCURRENTLY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_weeks', function (Blueprint $table): void {
            $table->smallInteger('rules_version')->default(1);
        });

        Schema::table('league_months', function (Blueprint $table): void {
            $table->smallInteger('rules_version')->default(1);
        });

        Schema::table('league_groups', function (Blueprint $table): void {
            $table->string('category', 4)->default('free');
            $table->index(['league_week_id', 'category', 'tier', 'members_count']);
        });

        Schema::table('league_members', function (Blueprint $table): void {
            $table->string('category', 4)->nullable();
            $table->smallInteger('final_long_words')->nullable();
            $table->smallInteger('final_days')->nullable();
        });

        Schema::table('league_month_scores', function (Blueprint $table): void {
            // NULL = a v1 month: one common table.
            $table->string('category', 4)->nullable();
            $table->integer('long_words')->default(0);
        });

        Schema::table('league_month_candidates', function (Blueprint $table): void {
            $table->string('category', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('league_month_candidates', function (Blueprint $table): void {
            $table->dropColumn('category');
        });

        Schema::table('league_month_scores', function (Blueprint $table): void {
            $table->dropColumn(['category', 'long_words']);
        });

        Schema::table('league_members', function (Blueprint $table): void {
            $table->dropColumn(['category', 'final_long_words', 'final_days']);
        });

        Schema::table('league_groups', function (Blueprint $table): void {
            $table->dropIndex(['league_week_id', 'category', 'tier', 'members_count']);
        });
        Schema::table('league_groups', function (Blueprint $table): void {
            $table->dropColumn('category');
        });

        Schema::table('league_months', function (Blueprint $table): void {
            $table->dropColumn('rules_version');
        });

        Schema::table('league_weeks', function (Blueprint $table): void {
            $table->dropColumn('rules_version');
        });
    }
};
