<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes under the list pages of `/admin` and `/org` (docs/20 F2 — A2, A3, A7; docs/03 §2.7, §2.11, §2.17), each
 * taken from the SQL the pages really run and checked with EXPLAIN (ANALYZE, BUFFERS) on the perf:seed data set
 * (200 000 learners, 100 000 words; docs/20 §4 "Индексы F2").
 *
 *  - B-tree `(sort column, id)` under the default sorts: Filament orders by the column and then by the primary key, so
 *    the first page is the first entries of the index instead of a sort of the whole table — `learners.first_name`
 *    (A2: Parallel Seq Scan of 200 000 rows + top-N sort) and `exam_sessions.started_at` (A7).
 *  - GIN trigram indexes (`pg_trgm`) under the searches, which are `like '%…%'` and cannot use a b-tree: the names
 *    and the username of learners (`/admin/learners`, `/org/…/members`), the word and the translation of words
 *    (`/admin/words`). Filament's own search on PostgreSQL compares `lower(column) like`, so the indexes on the
 *    name and word columns are on `lower(column)`; `username` is searched with `ilike` on the plain column
 *    (LearnerResource, MemberResource) and gets a plain-column index — pg_trgm serves LIKE and ILIKE alike.
 *
 * PostgreSQL only for the extension and the GIN indexes (sqlite has neither; the suite runs there without them).
 * Like 2026_10_28_000001, PostgreSQL builds and drops the indexes `CONCURRENTLY` (no SHARE lock on `learners` or
 * `words` for the build), which cannot run inside a transaction block — hence `$withinTransaction = false`; inside a
 * caller's transaction (RefreshDatabase in tests) the plain statement is used. `DROP INDEX IF EXISTS` before each
 * build clears an INVALID index left by an interrupted CONCURRENTLY build, so a re-run is clean.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    /** @var array<string, array{0: string, 1: string}> index name => [table, definition]; every driver */
    private const BTREE = [
        'learners_first_name_id_idx' => ['learners', '(first_name, id)'],
        'exam_sessions_started_at_id_idx' => ['exam_sessions', '(started_at, id)'],
    ];

    /** @var array<string, array{0: string, 1: string}> index name => [table, definition]; PostgreSQL only */
    private const TRGM = [
        'learners_first_name_lower_trgm_idx' => ['learners', '(lower(first_name) gin_trgm_ops)'],
        'learners_last_name_lower_trgm_idx' => ['learners', '(lower(last_name) gin_trgm_ops)'],
        'learners_username_trgm_idx' => ['learners', '(username gin_trgm_ops)'],
        'words_word_lower_trgm_idx' => ['words', '(lower(word) gin_trgm_ops)'],
        'words_translation_lower_trgm_idx' => ['words', '(lower(translation) gin_trgm_ops)'],
    ];

    public function up(): void
    {
        foreach (self::BTREE as $name => [$table, $definition]) {
            $this->createIndex($name, $table, $definition);
        }

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach (self::TRGM as $name => [$table, $definition]) {
            $this->createIndex($name, $table, $definition, 'gin');
        }
    }

    /**
     * Drops the indexes. The extension stays: `pg_trgm` is shared infrastructure that later indexes and queries may come to
     * depend on, and dropping it is a decision of its own, not a side effect of rolling back a set of indexes.
     */
    public function down(): void
    {
        if ($this->isPostgres()) {
            foreach (array_reverse(array_keys(self::TRGM)) as $name) {
                $this->dropIndex($name);
            }
        }

        foreach (array_reverse(array_keys(self::BTREE)) as $name) {
            $this->dropIndex($name);
        }
    }

    private function createIndex(string $name, string $table, string $definition, string $method = 'btree'): void
    {
        // A leftover INVALID index from an interrupted CONCURRENTLY build would make IF NOT EXISTS skip the rebuild.
        $this->dropIndex($name);

        $using = $this->isPostgres() ? "USING {$method} " : '';

        DB::statement("CREATE INDEX {$this->concurrently()}{$name} ON {$table} {$using}{$definition}");
    }

    private function dropIndex(string $name): void
    {
        DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function concurrently(): string
    {
        return $this->isPostgres() && DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
