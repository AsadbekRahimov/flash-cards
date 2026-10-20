<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lesson assignments of a center for one of its Telegram groups (docs/13 §1.4). `announced_at` /
 * `reminded_at` are the claims of the one-time group posts (conditional UPDATE, never twice);
 * `status` is `active` / `closed` (a due date that passed does not close by itself — see the
 * "Реализация 2B.4" notes). `lesson_id` is RESTRICT: a lesson with assignments cannot be deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->string('title', 120)->nullable();
            $table->timestamp('due_at');
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->string('status', 10)->default('active'); // active | closed
            $table->unsignedSmallInteger('completion_threshold')->default(100);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['telegram_group_id', 'status', 'due_at']);
            $table->index(['organization_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
