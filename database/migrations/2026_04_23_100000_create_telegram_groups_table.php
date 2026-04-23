<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_groups', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->string('title', 255);
            $table->enum('status', ['pending', 'active', 'disabled'])->default('pending')->index();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};
