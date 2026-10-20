<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learners', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_user_id')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('username', 100)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('interface_locale', 5)->default('ru');
            $table->string('timezone', 64)->default('Asia/Tashkent');
            $table->string('goal', 20)->nullable();
            $table->string('level', 2)->nullable();
            $table->foreignId('active_course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->smallInteger('daily_goal')->default(20);
            $table->time('reminder_time')->nullable()->default('19:00');
            $table->timestamp('next_reminder_at')->nullable();
            $table->timestamp('dm_blocked_at')->nullable();
            $table->string('onboarding_state', 16)->default('new');
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->string('acquisition_source', 64)->nullable();
            $table->string('start_payload', 64)->nullable();
            $table->foreignId('referrer_learner_id')->nullable()->constrained('learners')->nullOnDelete();
            // 2C.1 (docs/13 §1.5): the learner's own invite code, generated lazily by ReferralService::codeFor.
            $table->string('referral_code', 12)->nullable()->unique();
            $table->smallInteger('streak_current')->default(0);
            $table->smallInteger('streak_best')->default(0);
            $table->date('streak_last_date')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->softDeletes();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index('onboarding_state');
            $table->index('last_seen_at');
            // FK columns are not indexed automatically on PostgreSQL (needed for ON DELETE SET NULL scans).
            $table->index('active_course_id');
            $table->index('referrer_learner_id');
        });

        // Reminder scan only needs reachable learners with a scheduled reminder.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX learners_next_reminder_at_idx
                 ON learners (next_reminder_at)
                 WHERE next_reminder_at IS NOT NULL AND dm_blocked_at IS NULL',
            );
        } else {
            Schema::table('learners', function (Blueprint $table): void {
                $table->index('next_reminder_at', 'learners_next_reminder_at_idx');
            });
        }
    }

    public function down(): void
    {
        // Dropping the table drops its indexes on every driver.
        Schema::dropIfExists('learners');
    }
};
