<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A partial index over the soft-deleted learners (docs/20 §4 "Индексы F2", the widgets of the dashboard of `/admin`).
 *
 * A member row stays when its learner is soft-deleted, so the seat figures and the rankings of the dashboard must leave the
 * deleted ones out. Asking "is this learner deleted" with a join of `learners` (`deleted_at is null`) costs a primary-key lookup
 * per member row - 32 000 lookups and 128 000 buffers for the seat table of 100 organizations at 200 000 learners, 74 ms.
 * Deleted learners are a handful: an anti-join against them (`not exists (… where deleted_at is not null)`) reads this tiny
 * index instead, and the same form keeps the top-learners aggregate from joining every learner to every review.
 *
 * Added together with the queries that use it (`AdminDashboardStats`) and for no other reason. Both drivers support a partial
 * index; PostgreSQL builds and drops it `CONCURRENTLY` (no SHARE lock on `learners`), which cannot run inside a transaction
 * block - hence `$withinTransaction = false`; inside a caller's transaction (RefreshDatabase in tests) the plain statement is
 * used, as in 2026_11_02_100001. `DROP INDEX IF EXISTS` before the build clears an INVALID index left by an interrupted run.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const INDEX = 'learners_deleted_idx';

    public function up(): void
    {
        $this->dropIndex();

        DB::statement('CREATE INDEX '.$this->concurrently().self::INDEX.' ON learners (id) WHERE deleted_at IS NOT NULL');
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
