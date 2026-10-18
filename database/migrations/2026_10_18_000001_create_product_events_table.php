<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_events', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_groups')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('props')->default('{}');
            $table->timestamp('occurred_at');

            $table->index(['name', 'occurred_at']);
            $table->index(['student_id', 'occurred_at']);
            // FK columns: ON DELETE SET NULL scans these tables, plus per-group / per-teacher analytics.
            $table->index(['telegram_group_id', 'occurred_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_events');
    }
};
