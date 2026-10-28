<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the partial index `word_repetitions_learner_is_hard_idx (learner_id) WHERE is_hard = true`, created together
 * with the table (2026_04_23_100006_create_word_repetitions_table); docs/03 §2.8.
 *
 * Nothing reads it. A partial index serves only a query whose WHERE implies `is_hard = true`, and no query of the app
 * filters by `is_hard`: `CardPicker::pickNext` only ORDERS BY it (served by `word_repetitions_next_card_idx`,
 * 2026_10_28_000001), `ExamAnswerService` reads the value by `(learner_id, word_id)`, the schedulers write it. On
 * PostgreSQL 16 with 90 000 rows (30 learners, 12 % hard), EXPLAIN ANALYZE of the statements of the real code paths
 * (/today, /courses, the session and review flow, CardPicker, LearnerStatsService, DueRepetitionCriteria, the exam
 * lookup, the Filament learner list) and the generic plans of all 163 DML statements the test suite issues against the
 * table show it in no plan; `idx_scan` stayed 0 over the whole suite. It only costs: each `POST /review` that leaves a
 * card hard writes a new entry into it (vacuum removes the old one later).
 *
 * Like 2026_10_28_000001, PostgreSQL drops it `CONCURRENTLY` (a plain DROP takes an ACCESS EXCLUSIVE lock that every
 * `POST /review` would queue behind), which cannot run inside a transaction block, hence `$withinTransaction = false`.
 * Inside a transaction of the caller (the test suite runs `migrate:fresh` / `migrate:rollback` in RefreshDatabase) the
 * plain statement is used. sqlite has the index too (the table migration creates it on every driver) and drops it with
 * the same plain statement.
 *
 * `down()` rebuilds it exactly as it was.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->dropIndex('word_repetitions_learner_is_hard_idx');
    }

    public function down(): void
    {
        // A leftover INVALID index from an interrupted CONCURRENTLY build would make the rebuild a no-op: clear it first.
        $this->dropIndex('word_repetitions_learner_is_hard_idx');

        DB::statement(
            "CREATE INDEX {$this->concurrently()}word_repetitions_learner_is_hard_idx
             ON word_repetitions (learner_id)
             WHERE is_hard = true",
        );
    }

    private function dropIndex(string $name): void
    {
        DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
    }

    private function concurrently(): string
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
