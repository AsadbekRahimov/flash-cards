<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('words', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('word', 100);
            $table->string('translation', 500);
            $table->text('example')->nullable();
            $table->string('part_of_speech', 30)->nullable();
            $table->string('transcription', 100)->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['lesson_id', 'word']);
            $table->index('lesson_id');
            $table->index('word');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('words');
    }
};
