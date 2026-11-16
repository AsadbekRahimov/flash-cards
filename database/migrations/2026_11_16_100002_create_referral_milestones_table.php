<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The milestones of "Invite a friend" (docs/13 §1.5, §7.1, "Реализация 2C.9"): the inviter's book of rewards. A row is
 * written when the number of counted friends (`referrals.status = rewarded`) reaches a configured milestone
 * (`referrals.milestones`), under the inviter's referral row locks; the unique `(referrer_learner_id, milestone)` is
 * what makes "each milestone exactly once" hold whatever the job retries, the recovery sweep or two friends activated at
 * the same instant do. The grant itself (`granted_at`, `subscription_id` or `skipped`) is a later transaction of its
 * own: a row with `granted_at IS NULL` is a reward still owed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_learner_id')->constrained('learners')->cascadeOnDelete();
            $table->unsignedSmallInteger('milestone');
            $table->unsignedSmallInteger('days');
            // The referral whose counting completed the milestone (SET NULL once that learner is purged).
            $table->foreignId('referral_id')->nullable()->constrained('referrals')->nullOnDelete();
            $table->timestamp('reached_at');
            $table->timestamp('granted_at')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            // `perpetual` / `learner_missing` / `learner_deleted` / `learner_blocked`: granted nothing, and why.
            $table->string('skipped', 24)->nullable();
            $table->timestamps();

            $table->unique(['referrer_learner_id', 'milestone']);
            // The recovery sweep: owed rewards within its window.
            $table->index(['granted_at', 'reached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_milestones');
    }
};
