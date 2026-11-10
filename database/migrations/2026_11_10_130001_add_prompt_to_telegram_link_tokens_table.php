<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `/start link_<token>` no longer connects the account in one tap (review of the audit 2026-11-10 H1, finding W2): the bot
 * first asks "Connect this Telegram to the cabinet of X?" and remembers WHO was asked — `prompted_telegram_user_id` is the
 * `from.id` that opened the link. "Yes" is accepted from that Telegram account only, so a forwarded question or a crafted
 * callback from another account cannot spend the token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_link_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('prompted_telegram_user_id')->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_link_tokens', function (Blueprint $table): void {
            $table->dropColumn('prompted_telegram_user_id');
        });
    }
};
