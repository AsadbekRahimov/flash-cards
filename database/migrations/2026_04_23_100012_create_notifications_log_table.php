<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('type', 50);
            $table->jsonb('payload')->default('{}');
            $table->timestamp('sent_at');

            $table->index(['student_id', 'type', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications_log');
    }
};
