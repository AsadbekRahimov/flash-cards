<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The duels of a cup (docs/33 §2.9, §5.7, §11.9; D1): `cup_duels` (a challenge and its match), `cup_duel_slots` ("one rated duel per
 * participant and day" - UNIQUE `(participant_id, day)`), and on `battles` the link `cup_duel_id` (the clock of a battle knows the match
 * is a duel of a cup) and `limit_exempt` (a rated duel does not spend the Free limit of battles, docs/33 В5 / owner's К5).
 *
 * Indexes serve only the queries of the code (docs/33 §11.9):
 *  - `cup_duels (cup_id, day)` - the duels of a cup's day (limits of the challenge, the list of the Mini App, the finish) and the cascade
 *    of a cup; `day` never changes;
 *  - unique `(challenger_participant_id, request_id)` - a repeated request; the index of the FK of the challenger;
 *  - `(opponent_participant_id)` - the challenges to me; the index of the FK of the opponent;
 *  - PARTIAL unique `open_challenger_id WHERE NOT NULL` - one open challenge per participant (a fact of the database, as
 *    `cups.active_group_id`); only the open rows are in it (a full unique on the stand: 1.9 MB of NULLs for 300 000 duels, partial: 16 kB);
 *  - PARTIAL unique `battle_id WHERE NOT NULL` - one duel per match; the index of the FK (`battle_id = $1` implies NOT NULL);
 *  - PARTIAL `cup_duels_open_expiry_idx (expires_at) WHERE status = 'open'` and `cup_duels_accepted_idx (accepted_at) WHERE status =
 *    'accepted'` - `cups:sweep-duels` reads only the rows still open / not scored, never the history;
 *  - `cup_duel_slots`: unique `(participant_id, day)` (the quota; the FK of the participant), `(cup_duel_id)` (the release of a void
 *    duel; the FK); `cup_id` is a copy without a key (the rows go with the participant and the duel);
 *  - `battles`: PARTIAL unique `(cup_duel_id) WHERE cup_duel_id IS NOT NULL` - one match per duel and the index of the FK without a
 *    row for every ordinary battle. No index on `limit_exempt`: it is a filter of the count of one learner's battles.
 *  `winner_participant_id` is a copy without a key: the winner is one of the two players, whose rows take the duel with them.
 *
 * On SQLite the foreign key of `battles` is added by rebuilding the table; the partial index is made after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cup_duels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->smallInteger('day'); // the day of the challenge
            $table->foreignId('challenger_participant_id')->constrained('cup_participants')->cascadeOnDelete();
            $table->foreignId('opponent_participant_id')->nullable()->constrained('cup_participants')->cascadeOnDelete();
            $table->boolean('is_public')->default(false);
            $table->boolean('rated_offered');
            $table->boolean('rated')->nullable();
            $table->string('mode', 12);
            $table->string('status', 10); // open | accepted | declined | expired | canceled | done | void
            $table->string('end_reason', 16)->nullable();
            $table->unsignedBigInteger('open_challenger_id')->nullable(); // PARTIAL unique below
            $table->foreignId('battle_id')->nullable()->constrained('battles')->nullOnDelete(); // PARTIAL unique below
            $table->uuid('request_id');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->smallInteger('accepted_day')->nullable(); // the day of the points (docs/33 §5.7: the day of the acceptance)
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->smallInteger('challenger_points')->nullable();
            $table->smallInteger('opponent_points')->nullable();
            $table->unsignedBigInteger('winner_participant_id')->nullable();
            $table->timestamps();

            $table->index(['cup_id', 'day']);
            $table->unique(['challenger_participant_id', 'request_id']);
            $table->index('opponent_participant_id');
        });

        Schema::create('cup_duel_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cup_id');
            $table->foreignId('participant_id')->constrained('cup_participants')->cascadeOnDelete();
            $table->smallInteger('day');
            $table->foreignId('cup_duel_id')->constrained('cup_duels')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['participant_id', 'day']);
            $table->index('cup_duel_id');
        });

        Schema::table('battles', function (Blueprint $table): void {
            $table->unsignedBigInteger('cup_duel_id')->nullable();
            $table->boolean('limit_exempt')->default(false);
            $table->foreign('cup_duel_id')->references('id')->on('cup_duels')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX battles_cup_duel_id_unique ON battles (cup_duel_id) WHERE cup_duel_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX cup_duels_open_challenger_unique ON cup_duels (open_challenger_id) WHERE open_challenger_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX cup_duels_battle_unique ON cup_duels (battle_id) WHERE battle_id IS NOT NULL');
        DB::statement("CREATE INDEX cup_duels_open_expiry_idx ON cup_duels (expires_at) WHERE status = 'open'");
        DB::statement("CREATE INDEX cup_duels_accepted_idx ON cup_duels (accepted_at) WHERE status = 'accepted'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE cup_duels ADD CONSTRAINT cup_duels_shape_check
                 CHECK (status IN ('open','accepted','declined','expired','canceled','done','void')
                    AND day >= 1
                    AND (opponent_participant_id IS NULL OR opponent_participant_id <> challenger_participant_id)
                    AND ((status = 'open') = (open_challenger_id IS NOT NULL))
                    AND (open_challenger_id IS NULL OR open_challenger_id = challenger_participant_id)
                    AND ((status IN ('accepted','done','void')) = (accepted_at IS NOT NULL))
                    AND (accepted_at IS NULL OR (accepted_day >= 1 AND rated IS NOT NULL AND opponent_participant_id IS NOT NULL))
                    AND ((status IN ('done','void')) = (scored_at IS NOT NULL))
                    AND ((status IN ('open','accepted')) = (closed_at IS NULL))
                    AND (challenger_points IS NULL OR challenger_points BETWEEN 0 AND 5)
                    AND (opponent_points IS NULL OR opponent_points BETWEEN 0 AND 5))",
            );
            DB::statement(
                "ALTER TABLE cup_duels ADD CONSTRAINT cup_duels_end_reason_check
                 CHECK (end_reason IS NULL OR end_reason IN ('declined','expired','cup_over','challenger','cup_closed','left',
                    'challenger_limit','material','no_answers','battle_gone'))",
            );
            DB::statement('ALTER TABLE cup_duel_slots ADD CONSTRAINT cup_duel_slots_day_check CHECK (day >= 1)');
            // No CHECK ties `battles.limit_exempt` to `cup_duel_id`: the link is SET NULL when a cup goes (a cascade), the match stays.
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS battles_cup_duel_id_unique');

        // The duels go, so the links to them mean nothing: without this the next `up()` would meet ids with no row behind them.
        DB::table('battles')->whereNotNull('cup_duel_id')->update(['cup_duel_id' => null]);

        Schema::table('battles', function (Blueprint $table): void {
            $table->dropForeign(['cup_duel_id']);
        });
        Schema::table('battles', function (Blueprint $table): void {
            $table->dropColumn(['cup_duel_id', 'limit_exempt']);
        });

        Schema::dropIfExists('cup_duel_slots');
        Schema::dropIfExists('cup_duels');
    }
};
