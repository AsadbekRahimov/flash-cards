<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `cup_duels.end_reason = battle_voided` (docs/33 §11.11 п. 4): a duel whose match the system called off (`battles.end_reason =
 * voided` - a clip missing, the practice switched off) is `void` whatever was answered. PostgreSQL only - SQLite has no CHECK of the
 * reasons. The new CHECK goes in `NOT VALID` (a moment of the lock, no scan) and is validated in a separate statement (the writes of
 * the duels go on). `down()` turns the rows of the new reason into `battle_gone` (the nearest of the old ones: the match broke off)
 * before the old CHECK comes back.
 */
return new class extends Migration
{
    private const OLD = "'declined','expired','cup_over','challenger','cup_closed','left','challenger_limit','material','no_answers','battle_gone'";

    public function up(): void
    {
        $this->replace(self::OLD.",'battle_voided'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::table('cup_duels')->where('end_reason', 'battle_voided')->update(['end_reason' => 'battle_gone']);
        $this->replace(self::OLD);
    }

    private function replace(string $reasons): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE cup_duels DROP CONSTRAINT IF EXISTS cup_duels_end_reason_check');
        DB::statement("ALTER TABLE cup_duels ADD CONSTRAINT cup_duels_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ({$reasons})) NOT VALID");
        DB::statement('ALTER TABLE cup_duels VALIDATE CONSTRAINT cup_duels_end_reason_check');
    }
};
