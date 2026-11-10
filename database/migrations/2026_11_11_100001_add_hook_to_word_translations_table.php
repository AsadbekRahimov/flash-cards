<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/25 §3.4, docs/06 §12.3 — the authored memory hook of a word in the learner's language (a morphological cue, a
 * familiar word, a sound association) lives next to the translation of that language: `word_translations.hook`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_translations', function (Blueprint $table): void {
            $table->string('hook', 500)->nullable()->after('example_translation');
        });
    }

    public function down(): void
    {
        Schema::table('word_translations', function (Blueprint $table): void {
            $table->dropColumn('hook');
        });
    }
};
