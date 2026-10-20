<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals (docs/13 §1.5, task 2C.1): one row per invited learner (`referee_learner_id` unique),
 * `pending` → `activated` → `rewarded`, or `rejected` with a reason (`self`, `existing_learner`,
 * `referrer_capped`, `flagged`). `flagged_at` parks the reward until an admin clears the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('referee_learner_id')->unique()->constrained('learners')->cascadeOnDelete();
            $table->string('status', 12);
            $table->string('reject_reason', 24)->nullable();
            $table->timestamp('linked_at');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('flagged_at')->nullable();
            // `{rewards: {referee: {...}, referrer: {...}}, reward_subscription_ids: [...], active_days: [...]}`.
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['referrer_learner_id', 'status', 'linked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
