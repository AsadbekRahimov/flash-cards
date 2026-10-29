<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `stages.title` becomes nullable (docs/03 §2.5, docs/14 §6.5): a module without a name of its own is NULL, and the
 * reader is given its caption in their own language when it is shown ("Модуль 3" / "3-modul"). Until now an import
 * file without `stage.title` and the demo seeder wrote the English "Stage 3", and an uz-learner read it as it was.
 *
 * up(): a title that is EXACTLY "Stage {number}" with the module's own number is the placeholder, not a name somebody
 * chose, and becomes NULL; "Stage 3" on module 5, "Stage 03" and every real name stay as they are.
 * down(): NULL titles get "Stage {number}" back, then the column is NOT NULL again.
 *
 * The comparison is one statement with the prefix as a binding (no row is read into PHP, a writer that renames a
 * module meanwhile is not overwritten). PostgreSQL only drops / sets the constraint: no rewrite, the table is locked for
 * an instant. SQLite cannot do that in place, and the way Laravel emulates it - rebuild the table, `DROP TABLE stages` -
 * deletes the lessons of the modules (and their words) through the foreign keys whenever the migration runs inside a
 * transaction (the test suite rolls migrations back and forward inside one; `PRAGMA foreign_keys = OFF` is a no-op there).
 * So on SQLite the NOT NULL is edited in the stored definition of the table, the way SQLite documents for removing and
 * adding NOT NULL ("Making Other Kinds Of Table Schema Changes", the simpler procedure): no table is rebuilt, the
 * unique `(course_id, number)`, the foreign key and every row stay, and a transaction around the migration undoes it.
 */
return new class extends Migration
{
    private const PLACEHOLDER = 'Stage ';

    public function up(): void
    {
        $this->allowNullTitle(true);

        DB::update('UPDATE stages SET title = NULL WHERE title = CAST(? AS VARCHAR(255)) || CAST(number AS VARCHAR(20))', [self::PLACEHOLDER]);
    }

    public function down(): void
    {
        DB::update('UPDATE stages SET title = CAST(? AS VARCHAR(255)) || CAST(number AS VARCHAR(20)) WHERE title IS NULL', [self::PLACEHOLDER]);

        $this->allowNullTitle(false);
    }

    private function allowNullTitle(bool $allow): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->editSqliteDefinition($allow);

            return;
        }

        Schema::table('stages', function (Blueprint $table) use ($allow): void {
            $table->string('title', 255)->nullable($allow)->change();
        });
    }

    /** Adds or removes `not null` in the stored `CREATE TABLE` text of `stages` (SQLite only). */
    private function editSqliteDefinition(bool $allowNull): void
    {
        $definition = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'stages'");
        $edited = preg_replace('/("title" varchar(?:\(\d+\))?)( not null)?/', '$1'.($allowNull ? '' : ' not null'), $definition, 1, $found);

        if ($edited === null || $found !== 1) {
            throw new RuntimeException('The definition of the sqlite table "stages" has no "title" varchar column to edit.');
        }

        if ($edited === $definition) {
            return; // already in the wanted shape (a retry after an interrupted run)
        }

        DB::transaction(function () use ($edited): void {
            $version = (int) DB::scalar('PRAGMA schema_version');

            DB::statement('PRAGMA writable_schema = ON');

            try {
                DB::update("UPDATE sqlite_master SET sql = ? WHERE type = 'table' AND name = 'stages'", [$edited]);
                // The new version makes every connection read the definition again.
                DB::statement('PRAGMA schema_version = '.($version + 1));
            } finally {
                DB::statement('PRAGMA writable_schema = OFF');
            }
        });
    }
};
