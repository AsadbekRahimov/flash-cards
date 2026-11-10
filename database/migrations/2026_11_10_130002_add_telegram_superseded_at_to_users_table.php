<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-typed, unverified `users.telegram_user_id` (legacy rows, `/admin`) gives way to the person who proves the account
 * through the bot (review of the audit 2026-11-10 H1, finding W1): the id is taken from the unverified holder, and
 * `telegram_superseded_at` tells their profile why Telegram is suddenly disconnected ("the account was confirmed by another
 * user"). Cleared by the next successful link or a disconnect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('telegram_superseded_at')->nullable()->after('telegram_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('telegram_superseded_at');
        });
    }
};
