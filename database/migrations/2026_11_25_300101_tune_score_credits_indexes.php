<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `score_credits` is the hottest insert of the word points (docs/32 §2.5, §2.7: one row per credited answer, 18–90 million a year), so
 * it carries only what is read (S4, the review of the indexes on PostgreSQL, docs/03 §2.70):
 *
 *   - the B-tree `score_credits_rating_day_index` becomes a BRIN `score_credits_rating_day_brin`: its only readers are the clean-up of
 *     the closed months (`DELETE … WHERE rating_day < ?`, S16) and an audit range; the rows arrive in the order of the rating day, so a
 *     BRIN of a few pages serves the range, and the insert stops updating a B-tree of one entry per row (the reads per learner go
 *     through the unique `(learner_id, rating_day, lexeme_id)`);
 *   - the foreign key `score_credits.lexeme_id → lexemes` goes: every insert checked it with a `FOR KEY SHARE` lock on the lexeme's row -
 *     a row shared by every learner of the word, so a common word under load turns those locks into multixacts - and it protects
 *     nothing: a lexeme is never deleted or updated (LexemeCatalog), and the journal has no FK on `word_id` / `source_id` either (it
 *     outlives what it points to). `learner_lexemes.lexeme_id` keeps its FK: that row is inserted once per learner and lexeme in a life;
 *   - a partial `score_credits_voided_by_idx (voided_by_user_id) WHERE voided_by_user_id IS NOT NULL`: the FK `voided_by_user_id →
 *     users ON DELETE SET NULL` made the deletion of an administrator scan the whole journal; the index holds only the voided rows, and an
 *     insert (voided_by_user_id NULL) never touches it.
 *
 * PostgreSQL only: on sqlite (the tests) the journal stays as 2026_11_25_300004 made it - BRIN and dropping a constraint in place do not
 * exist there, and the behaviour does not depend on them. The table is empty until the word points are switched on, so the plain
 * (non-CONCURRENTLY) statements take no time; `down()` restores the shape of 300004.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS score_credits_rating_day_index');
        DB::statement('CREATE INDEX IF NOT EXISTS score_credits_rating_day_brin ON score_credits USING brin (rating_day)');
        DB::statement('ALTER TABLE score_credits DROP CONSTRAINT IF EXISTS score_credits_lexeme_id_foreign');
        DB::statement('CREATE INDEX IF NOT EXISTS score_credits_voided_by_idx ON score_credits (voided_by_user_id) WHERE voided_by_user_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS score_credits_voided_by_idx');
        DB::statement('ALTER TABLE score_credits DROP CONSTRAINT IF EXISTS score_credits_lexeme_id_foreign');
        DB::statement('ALTER TABLE score_credits ADD CONSTRAINT score_credits_lexeme_id_foreign FOREIGN KEY (lexeme_id) REFERENCES lexemes (id)');
        DB::statement('DROP INDEX IF EXISTS score_credits_rating_day_brin');
        DB::statement('CREATE INDEX IF NOT EXISTS score_credits_rating_day_index ON score_credits (rating_day)');
    }
};
