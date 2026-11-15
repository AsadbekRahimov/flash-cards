<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exams list of the `/org` cabinet: `telegram_group_id IN (groups of the center) ORDER BY started_at DESC, id DESC` (docs/20 §1.5:
 * the sort key plus the primary key Filament adds). The existing `(telegram_group_id, status, started_at)` cannot serve that sort
 * without a filter on the status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->index(['telegram_group_id', 'started_at', 'id'], 'exam_sessions_group_started_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('exam_sessions', function (Blueprint $table): void {
            $table->dropIndex('exam_sessions_group_started_id_idx');
        });
    }
};
