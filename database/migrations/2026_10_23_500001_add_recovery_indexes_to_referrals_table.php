<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `referrals:recover` (every 30 minutes) looks for `activated` / `rewarded` rows within a recent window by
 * `activated_at` / `rewarded_at` (docs/13 §7.1): one index per branch keeps it a range scan, not a table scan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->index(['status', 'activated_at'], 'referrals_status_activated_at_index');
            $table->index(['status', 'rewarded_at'], 'referrals_status_rewarded_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropIndex('referrals_status_activated_at_index');
            $table->dropIndex('referrals_status_rewarded_at_index');
        });
    }
};
