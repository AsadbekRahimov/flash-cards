<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G2.13 (docs/18 §12.11, the rule of G2.8b "a run without an answer costs nothing"): a fight with the boss of a class that is closed
 * WITHOUT A SINGLE ANSWER (abandoned, replaced, run out) lets go of its `class_boss_key`, so that it does not count as the member's one
 * attempt - the next start may fight. The CHECK of the shape of the key, `(kind = 'class_boss') = (class_boss_key IS NOT NULL)`, forbade
 * that; it becomes: a key only on a `class_boss` run, and a `class_boss` run without its key only when it is `finished` with
 * `answered = 0`. A live fight always carries its key (the unique index still makes "one fight at a time, one that counts" a fact).
 *
 * PostgreSQL only (SQLite carries no CHECKs of the game). `down()` gives the freed fights a unique stub key (`freed:{id}`: it fits the
 * column and cannot clash with a real `{caravan}:{learner}`; the real keys cannot be made up again, a later fight may hold the same one)
 * and then restores the former CHECK, validated. A CHECK left NOT VALID would still be checked on every UPDATE of an old row, and the
 * `down()` of the caravans migration (`UPDATE game_runs SET class_caravan_id = NULL`) and the `nullOnDelete` of that FK would fail.
 */
return new class extends Migration
{
    private const NAME = 'game_runs_class_boss_shape_check';

    private const BEFORE = "(kind = 'class_boss') = (class_boss_key IS NOT NULL)";

    private const AFTER = "(class_boss_key IS NULL OR kind = 'class_boss')"
        ." AND (kind <> 'class_boss' OR class_boss_key IS NOT NULL OR (status = 'finished' AND answered = 0))";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE game_runs DROP CONSTRAINT IF EXISTS '.self::NAME);
        DB::statement('ALTER TABLE game_runs ADD CONSTRAINT '.self::NAME.' CHECK ('.self::AFTER.')');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("UPDATE game_runs SET class_boss_key = 'freed:' || id WHERE kind = 'class_boss' AND class_boss_key IS NULL");
        DB::statement('ALTER TABLE game_runs DROP CONSTRAINT IF EXISTS '.self::NAME);
        DB::statement('ALTER TABLE game_runs ADD CONSTRAINT '.self::NAME.' CHECK ('.self::BEFORE.')');
    }
};
