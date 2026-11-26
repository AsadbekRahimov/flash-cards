<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The cards of the cups whose own row changed since their last render (docs/33 §6.3, K6): `cups:refresh-cards` picks, every five
 * minutes, the open cups with news (through the partial indexes of the open statuses) AND any cup - open or just finished / canceled -
 * whose row changed after its card was drawn (`card_rendered_at <= updated_at`: a start, a finish, a cancel, the settings). Without
 * this index the second part is a Seq Scan of every cup ever played (209 buffers for 5 000 cups on the stand, growing with the
 * history); with it, an Index Only Scan of the few rows that wait for a render. A row leaves the index when its card is drawn.
 *
 * Partial, so it holds a handful of rows, not the history; the cost - an UPDATE of `updated_at` / `card_rendered_at` /
 * `card_message_id` of a cup is not HOT - is paid by the transitions and the renders only (at most one in five minutes per card), never
 * by the answers of a round (they do not touch `cups`, docs/33 §5.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX cups_card_stale_idx ON cups (id) WHERE card_message_id IS NOT NULL AND card_rendered_at <= updated_at');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cups_card_stale_idx');
    }
};
