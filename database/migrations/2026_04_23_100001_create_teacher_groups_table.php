<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'telegram_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_groups');
    }
};
