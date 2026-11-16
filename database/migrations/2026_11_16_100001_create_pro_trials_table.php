<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The trial Pro ledger (docs/13 §1.5, "Реализация 2C.9"): one row per Telegram account that ever received the welcome
 * trial. The account is kept as an HMAC of its Telegram id (`ProTrialService::hashFor`), not the id itself: the row
 * outlives the learner (the purge removes `learners` and with it the raw id, docs/03 §8 rule 2), and its only job is
 * to refuse a second trial to the same person after "delete the account, register again". `learner_id` is the row
 * the trial went to while it exists (nulled by the purge); `subscription_id` the trial subscription (rows of
 * `subscriptions` are never deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pro_trials', function (Blueprint $table): void {
            $table->id();
            $table->string('telegram_hash', 64)->unique();
            $table->foreignId('learner_id')->nullable()->constrained('learners')->nullOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedSmallInteger('days');
            $table->timestamp('granted_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index('learner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pro_trials');
    }
};
