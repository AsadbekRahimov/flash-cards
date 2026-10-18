<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('translation', 500);
            $table->text('example_translation')->nullable();
            $table->string('source', 16)->default('import');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['word_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_translations');
    }
};
