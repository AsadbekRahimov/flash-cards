<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `words_lexeme_id_idx (lexeme_id)` (docs/32 §2.1): the copies of a lexeme across the courses, and the foreign key of
 * `words.lexeme_id` (a lexeme is never deleted by the application, but without the index a manual delete would scan
 * `words`). `words` holds every deck of every learner, so PostgreSQL builds and drops it `CONCURRENTLY`, which cannot run
 * inside a transaction block - hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests)
 * the plain statement is used, as in 2026_11_16_200002. `DROP INDEX IF EXISTS` first clears an INVALID index of an
 * interrupted run.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'words_lexeme_id_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX.' ON words (lexeme_id)');
    }

    public function down(): void
    {
        $this->dropIndex();
    }

    private function dropIndex(): void
    {
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX);
    }

    private function concurrently(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' && DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
