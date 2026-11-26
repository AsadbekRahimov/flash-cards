<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/33 §2.1, K2 - the bot's own status in the group as the last `my_chat_member` told it (`member`, `administrator`, `left`,
 * `kicked`, also `restricted` / `creator` as Telegram sends them) and the `date` of that update. An older update never overwrites a
 * newer one (App\Domain\Telegram\Handlers\MyChatMemberHandler). Unlike `status` (pending / active / disabled - the platform's
 * decision), this one only mirrors Telegram: a re-added bot shows `member` here while a disabled group stays `disabled`. No index:
 * nobody looks groups up by it, the cup reads it from the group's row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table): void {
            $table->string('bot_status', 16)->nullable()->after('status');
            $table->timestamp('bot_status_at')->nullable()->after('bot_status');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table): void {
            $table->dropColumn(['bot_status', 'bot_status_at']);
        });
    }
};
