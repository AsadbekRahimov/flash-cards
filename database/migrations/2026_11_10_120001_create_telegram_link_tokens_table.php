<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time deep-link tokens of the `/org` cabinet (audit 2026-11-10 H1 / P1):
 *
 *   kind = account  `t.me/<bot>?start=link_<token>` — connects the staff user's own Telegram account (`users.telegram_user_id`);
 *   kind = group    `t.me/<bot>?startgroup=grp_<token>` or `/link <token>` in a group — links that group to the organization.
 *
 * Only the SHA-256 of the token is stored (a leaked table does not give usable links). `used_at` is set on success only;
 * refused attempts update `last_result` / `last_attempt_at` so the cabinet can tell the staff member why, while the bot's
 * answer in a group stays neutral (L8). Rows are small and short-lived: the issuing service deletes the user's live rows of
 * the same kind when it creates a new one, and `model:prune` (`TelegramLinkToken::prunable()`, daily in `routes/console.php`)
 * deletes used and expired rows 30 days after they stopped being redeemable — a recent `last_result` is still shown to the
 * owner in the cabinet until then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 8); // account | group
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('last_result', 32)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
