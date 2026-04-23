<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('telegram_user_id');
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->string('username', 100)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['telegram_user_id', 'telegram_group_id']);
            $table->index('telegram_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
