<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `training_sessions (status)` goes: it is the leading column of `training_sessions_status_expires_at_index (status, expires_at)`,
 * so every query it serves (the sweep of expired sessions, the counts of open ones) is served by that index as well - the SQL audit
 * of 26.11.2026, docs/38 §5.
 *
 * The table takes a write on every opened session and every close, and each of them paid for this index: a status has two values, so
 * the index is two long lists of duplicates, the expensive kind to insert into. Measured on PostgreSQL 16 (315 thousand sessions):
 * 20 000 inserts 610 ms with it, 407 ms without; the plans of the readers stay index scans (`status_expires_at`).
 *
 * `down()` puts the index back. PostgreSQL: CONCURRENTLY outside the migrator's transaction (the table is written all the time);
 * sqlite (tests): the plain statements.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'training_sessions_status_index';

    public function up(): void
    {
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS '.self::INDEX);
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX '.$this->concurrently().'IF NOT EXISTS '.self::INDEX.' ON training_sessions (status)');
    }

    private function concurrently(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' && DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
