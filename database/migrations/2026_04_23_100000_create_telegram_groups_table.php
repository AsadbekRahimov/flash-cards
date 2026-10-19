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
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};
