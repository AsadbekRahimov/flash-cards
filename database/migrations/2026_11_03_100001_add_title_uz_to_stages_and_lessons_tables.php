<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `stages.title_uz` and `lessons.title_uz` (docs/03 §2.5-§2.6, R3c): the Uzbek name of a module / lesson beside the Russian
 * `title`. Both are optional: a reader of `uz` is given `title_uz`, else `title`, else nothing (the client names the module
 * "N-modul" by its number, docs/14 §8); a reader of `ru` reads `title`. A separate column rather than a JSON `title`
 * like the course's: the two string columns keep every reader of `stages.title` / `lessons.title` (admin tables and
 * their LIKE search, the title-nullable migration, assignments, seeders) as they are, and the pick by locale lives in
 * one service (DeckTitles).
 *
 * up(): adds the nullable columns at the end of each table (no rewrite on PostgreSQL, a plain ADD COLUMN on SQLite; neither
 * places a column "after" another). down(): drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stages', function (Blueprint $table): void {
            $table->string('title_uz', 255)->nullable();
        });

        Schema::table('lessons', function (Blueprint $table): void {
            $table->string('title_uz', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropColumn('title_uz');
        });

        Schema::table('stages', function (Blueprint $table): void {
            $table->dropColumn('title_uz');
        });
    }
};
