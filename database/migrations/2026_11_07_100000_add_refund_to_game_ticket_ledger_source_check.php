<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G2.8b (docs/18 §12.7.3): the ticket of a run that ended without a single answer comes back - a ledger entry with `source = refund`
 * and the key `refund:run:{run_id}`. The CHECK on `game_ticket_ledger.source` exists on PostgreSQL only (the economy migration), so
 * this is a PostgreSQL-only change; SQLite keeps the plain varchar.
 *
 * `down()` restores the former list and therefore needs no `refund` rows in the table (a rollback past G2.8b is a rollback of the
 * data of G2.8b as well).
 */
return new class extends Migration
{
    private const SOURCES_BEFORE = "'streak','referral','admin','spend'";

    private const SOURCES_AFTER = "'streak','referral','admin','spend','refund'";

    public function up(): void
    {
        $this->replaceCheck(self::SOURCES_AFTER);
    }

    public function down(): void
    {
        $this->replaceCheck(self::SOURCES_BEFORE);
    }

    private function replaceCheck(string $sources): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE game_ticket_ledger DROP CONSTRAINT IF EXISTS game_ticket_ledger_source_check');
        DB::statement("ALTER TABLE game_ticket_ledger ADD CONSTRAINT game_ticket_ledger_source_check CHECK (source IN ({$sources}))");
    }
};
