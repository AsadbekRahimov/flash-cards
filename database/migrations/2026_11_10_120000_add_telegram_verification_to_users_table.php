<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of ownership of `users.telegram_user_id` (audit 2026-11-10 H1): `telegram_verified_at` is set only by the bot
 * when the person opens their own `/start link_<token>` — a value typed by hand (superadmin in `/admin`, legacy rows)
 * stays unverified and cannot be used to prove ownership of a Telegram group. `telegram_username` / `telegram_name`
 * are what the bot saw at that moment, so the cabinet can show which account is connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('telegram_verified_at')->nullable()->after('telegram_user_id');
            $table->string('telegram_username', 64)->nullable()->after('telegram_verified_at');
            $table->string('telegram_name', 129)->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['telegram_verified_at', 'telegram_username', 'telegram_name']);
        });
    }
};
