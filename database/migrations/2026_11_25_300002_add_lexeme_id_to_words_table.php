<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `words.lexeme_id` (docs/32 §2.1, task S1): the lexeme of the word, NULL until it is linked (`LexemeCatalog`, the
 * command `lexemes:backfill`) and for a word of a learner's deck that matches nothing in the catalog (such a word is
 * studied as usual but earns no public points, docs/28). A nullable column without a default is a catalog-only change on
 * PostgreSQL (no table rewrite); the index is built CONCURRENTLY by the next migration.
 *
 * sqlite: the schema builder adds a foreign key by rebuilding the table, and the rebuild loses the predicate of the partial
 * `words_inactive_idx` (2026_10_28_000001). sqlite accepts `ADD COLUMN … REFERENCES` for a column whose default is NULL, so the
 * column is added in place there; the way down rebuilds (sqlite cannot drop a column of a foreign key) and puts the partial
 * index back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE words ADD COLUMN lexeme_id INTEGER NULL REFERENCES lexemes (id) ON DELETE SET NULL');

            return;
        }

        Schema::table('words', function (Blueprint $table): void {
            $table->foreignId('lexeme_id')->nullable()->after('lesson_id')->constrained('lexemes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('words', function (Blueprint $table): void {
                $table->dropColumn('lexeme_id');
            });
            DB::statement('DROP INDEX IF EXISTS words_inactive_idx');
            DB::statement('CREATE INDEX words_inactive_idx ON words (id) WHERE is_active = false');

            return;
        }

        Schema::table('words', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lexeme_id');
        });
    }
};
