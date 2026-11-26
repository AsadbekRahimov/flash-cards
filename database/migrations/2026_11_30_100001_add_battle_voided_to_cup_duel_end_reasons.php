<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `cup_duels.end_reason = battle_voided` (docs/33 §11.11 п. 4): a duel whose match the system called off (`battles.end_reason =
 * voided` - a clip missing, the practice switched off) is `void` whatever was answered. PostgreSQL only - SQLite has no CHECK of the
 * reasons. The old CHECK is dropped and the new one goes in `NOT VALID` in one short transaction (a moment of the lock, no scan, never a
 * moment without a CHECK); it is validated in a transaction of its own (`SHARE UPDATE EXCLUSIVE`: the writes of the duels go on) - hence
 * `$withinTransaction = false`, in the migrator's transaction the lock of the first step would be held through the scan. A run that
 * broke off starts again: the drop is `IF EXISTS`, `VALIDATE` repeats harmlessly. `down()` turns the rows of the new reason into
 * `battle_gone` (the nearest of the old ones: the match broke off) in the transaction that brings the old CHECK back.
 */
return new class extends Migration
{
    /**
     * The validation runs out of the transaction that adds the CHECK (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

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
        $this->replace(self::OLD, fn () => DB::table('cup_duels')->where('end_reason', 'battle_voided')->update(['end_reason' => 'battle_gone']));
    }

    private function replace(string $reasons, ?Closure $before = null): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::transaction(function () use ($reasons, $before): void {
            if ($before !== null) {
                $before();
            }
            DB::statement('ALTER TABLE cup_duels DROP CONSTRAINT IF EXISTS cup_duels_end_reason_check');
            DB::statement("ALTER TABLE cup_duels ADD CONSTRAINT cup_duels_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ({$reasons})) NOT VALID");
        });
        DB::statement('ALTER TABLE cup_duels VALIDATE CONSTRAINT cup_duels_end_reason_check');
    }
};
