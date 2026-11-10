<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/25 §3.5, docs/06 §12.4 — additional examples of a word. `words.example` stays the anchor (position 0, shown until
 * the card graduates); the rows here are positions 1.. and rotate after it. `translations` is `locale => text`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('word_examples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('text', 2000);
            $table->json('translations')->nullable();
            $table->timestamps();

            $table->unique(['word_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_examples');
    }
};
