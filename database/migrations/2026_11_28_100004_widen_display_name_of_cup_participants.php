<?php

declare(strict_types=1);

use App\Domain\Cup\Support\DisplayName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The name in a cup is counted in graphemes (docs/33 §11.8, review of release 5): up to 32 of what a person sees as one character,
 * and a grapheme of an emoji sequence is several code points (a family is 5, a flag 2) - `varchar(32)` counts code points and would
 * refuse at the INSERT what the rule allows. The column holds {@see DisplayName::MAX_CODE_POINTS} (64). On PostgreSQL a wider
 * `varchar` is a catalog change only (no rewrite of the table, no index on the column). SQLite does not enforce the length of a
 * `varchar` (and rebuilding the table there would only risk its indexes): nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE cup_participants ALTER COLUMN display_name TYPE varchar(64)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Back to 32 code points: a longer name is cut first (between code points - the rule of the old column).
        DB::update('UPDATE cup_participants SET display_name = substr(display_name, 1, ?) WHERE length(display_name) > ?', [32, 32]);
        DB::statement('ALTER TABLE cup_participants ALTER COLUMN display_name TYPE varchar(32)');
    }
};
