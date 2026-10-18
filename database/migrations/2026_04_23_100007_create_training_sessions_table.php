<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            // Personal sessions (mode <> group_lesson) have no group, teacher or (for daily_review) lesson.
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_groups')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('learners')->cascadeOnDelete();
            $table->string('mode', 16)->default('group_lesson');
            $table->string('source', 16)->default('command');
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'status', 'started_at']);
            $table->index(['status', 'expires_at']);
            // FK columns are not indexed automatically on PostgreSQL (needed for ON DELETE SET NULL scans).
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
