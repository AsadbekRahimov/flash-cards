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
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $table->foreignId('started_by_user_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open')->index();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
