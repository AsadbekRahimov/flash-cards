<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog of lexemes (docs/32 §2.1, task S1): one row per normalised spelling of a word of a language
 * (`App\Domain\Content\Support\LexemeKey`). It is the unit the word points are counted by (docs/28): the copies of one
 * English word in two courses, or in a course and a learner's deck, are different `words` rows but one lexeme.
 * Rows are created by the words of platform and organisation courses only (`LexemeCatalog::ensureFor`); a learner's deck
 * only looks one up. Never updated, never deleted by the application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lexemes', function (Blueprint $table): void {
            $table->id();
            $table->string('language', 5);
            $table->string('key', 100);
            $table->timestamp('created_at')->nullable();

            $table->unique(['language', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lexemes');
    }
};
