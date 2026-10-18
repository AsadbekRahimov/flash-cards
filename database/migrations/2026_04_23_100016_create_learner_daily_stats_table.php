<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->date('date');
            $table->integer('reviews_count')->default(0);
            $table->integer('new_words_count')->default(0);
            $table->integer('correct_count')->default(0);
            $table->bigInteger('time_spent_ms')->default(0);
            $table->timestamp('goal_met_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_daily_stats');
    }
};
