<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Caravans" page of `/org` (docs/18 §12.10, G2.9b, docs/20 §1) lists the caravans of one center, the newest first: the filter
 * `organization_id` and the sort `starts_at` get an index of their own (a teacher's list is served by `telegram_group_id, starts_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_caravans', function (Blueprint $table): void {
            $table->index(['organization_id', 'starts_at'], 'class_caravans_organization_starts_index');
        });
    }

    public function down(): void
    {
        Schema::table('class_caravans', function (Blueprint $table): void {
            $table->dropIndex('class_caravans_organization_starts_index');
        });
    }
};
