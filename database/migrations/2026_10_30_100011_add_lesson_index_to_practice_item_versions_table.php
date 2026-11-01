<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `practice_item_versions.lesson_id` (2026_10_30_100010: the lesson belongs to the version) is a foreign key with `ON DELETE SET NULL`
 * and no index of its own. PostgreSQL does not index the referencing column of a foreign key, so deleting a lesson has to scan the whole
 * table of versions to null the ones that name it - and the table is append-only, it only grows. The index answers that; the lookup of
 * "the versions of a lesson" uses it too. Its name is the one Laravel gives (`practice_item_versions_lesson_id_index`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_item_versions', function (Blueprint $table): void {
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::table('practice_item_versions', function (Blueprint $table): void {
            $table->dropIndex(['lesson_id']);
        });
    }
};
