<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `words.lexeme_id` (docs/32 §2.1, task S1): the lexeme of the word, NULL until it is linked (`LexemeCatalog`, the
 * command `lexemes:backfill`) and for a word of a learner's deck that matches nothing in the catalog (such a word is
 * studied as usual but earns no public points, docs/28). A nullable column without a default is a catalog-only change on
 * PostgreSQL (no table rewrite); the index is built CONCURRENTLY by the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->foreignId('lexeme_id')->nullable()->after('lesson_id')->constrained('lexemes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lexeme_id');
        });
    }
};
