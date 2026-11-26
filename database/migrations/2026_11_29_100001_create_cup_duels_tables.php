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
 * `battles` holds every match of every learner, so on PostgreSQL its part never holds the writes of the matches for the length of a
 * scan (docs/38): the two columns are added without a rewrite (a NULL one and a constant default), the foreign key `NOT VALID` (a short
 * lock, no scan) and validated in a separate step (`SHARE UPDATE EXCLUSIVE`: the writes go on), the partial unique index is built
 * `CONCURRENTLY` - which cannot run inside a transaction block, hence `$withinTransaction = false`; the new tables are made in a
 * transaction of their own. Inside a caller's transaction (RefreshDatabase in tests) the plain statements are used, as in
 * 2026_11_25_300003. On SQLite the foreign key of `battles` is added by rebuilding the table; the partial index is made after it.
 *
 * Out of a transaction a run can break off half-way (a lock timeout on `battles`), so both directions start again from any state
 * they leave: `up()` makes a table, a column, a constraint only when it is not there yet (`pg_constraint` is asked for the names,
 * `VALIDATE` repeats harmlessly, the index is dropped and built again), `down()` drops only what is there.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY is incompatible with the migrator's transaction (see the class docblock).
     *
     * @var bool
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(fn () => $this->createTables());
        $this->linkBattles();
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('cup_duels')) {
            $this->createDuels();
        }
        if (! Schema::hasTable('cup_duel_slots')) {
            $this->createSlots();
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS cup_duels_open_challenger_unique ON cup_duels (open_challenger_id) WHERE open_challenger_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS cup_duels_battle_unique ON cup_duels (battle_id) WHERE battle_id IS NOT NULL');
        DB::statement("CREATE INDEX IF NOT EXISTS cup_duels_open_expiry_idx ON cup_duels (expires_at) WHERE status = 'open'");
        DB::statement("CREATE INDEX IF NOT EXISTS cup_duels_accepted_idx ON cup_duels (accepted_at) WHERE status = 'accepted'");

        if (DB::getDriverName() === 'pgsql') {
            $this->addConstraint(
                'cup_duels',
                'cup_duels_shape_check',
                "CHECK (status IN ('open','accepted','declined','expired','canceled','done','void')
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
            $this->addConstraint(
                'cup_duels',
                'cup_duels_end_reason_check',
                "CHECK (end_reason IS NULL OR end_reason IN ('declined','expired','cup_over','challenger','cup_closed','left',
                    'challenger_limit','material','no_answers','battle_gone'))",
            );
            $this->addConstraint('cup_duel_slots', 'cup_duel_slots_day_check', 'CHECK (day >= 1)');
            // No CHECK ties `battles.limit_exempt` to `cup_duel_id`: the link is SET NULL when a cup goes (a cascade), the match stays.
        }
    }

    private function createDuels(): void
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
    }

    private function createSlots(): void
    {
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
    }

    /** The link of a match to its duel (see the class): the columns, the foreign key, the partial unique index. */
    private function linkBattles(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // One rebuild of the table makes both columns and the key: they are there together or not at all.
            if (! Schema::hasColumn('battles', 'cup_duel_id')) {
                Schema::table('battles', function (Blueprint $table): void {
                    $table->unsignedBigInteger('cup_duel_id')->nullable();
                    $table->boolean('limit_exempt')->default(false);
                    $table->foreign('cup_duel_id')->references('id')->on('cup_duels')->nullOnDelete();
                });
            }
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS battles_cup_duel_id_unique ON battles (cup_duel_id) WHERE cup_duel_id IS NOT NULL');

            return;
        }

        // A NULL column and a constant default: no rewrite of the table (PostgreSQL 11+), a moment of its lock.
        DB::statement('ALTER TABLE battles ADD COLUMN IF NOT EXISTS cup_duel_id bigint NULL, ADD COLUMN IF NOT EXISTS limit_exempt boolean NOT NULL DEFAULT false');
        $this->addConstraint('battles', 'battles_cup_duel_id_foreign', 'FOREIGN KEY (cup_duel_id) REFERENCES cup_duels (id) ON DELETE SET NULL NOT VALID');
        // Validating a valid constraint again is a no-op: a run that broke off after it repeats it harmlessly.
        DB::statement('ALTER TABLE battles VALIDATE CONSTRAINT battles_cup_duel_id_foreign');
        // An INVALID index of an interrupted run goes first.
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS battles_cup_duel_id_unique');
        DB::statement('CREATE UNIQUE INDEX '.$this->concurrently().'battles_cup_duel_id_unique ON battles (cup_duel_id) WHERE cup_duel_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX '.$this->concurrently().'IF EXISTS battles_cup_duel_id_unique');

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE battles DROP CONSTRAINT IF EXISTS battles_cup_duel_id_foreign');
                DB::statement('ALTER TABLE battles DROP COLUMN IF EXISTS cup_duel_id, DROP COLUMN IF EXISTS limit_exempt');
            } elseif (Schema::hasColumn('battles', 'cup_duel_id')) {
                // SQLite: the key goes first (a rebuild of the table), a column under a key cannot be dropped.
                Schema::table('battles', function (Blueprint $table): void {
                    $table->dropForeign(['cup_duel_id']);
                });
                Schema::table('battles', function (Blueprint $table): void {
                    $table->dropColumn(['cup_duel_id', 'limit_exempt']);
                });
            }

            Schema::dropIfExists('cup_duel_slots');
            Schema::dropIfExists('cup_duels');
        });
    }

    /** Adds the constraint unless one of that name is on the table already (a run that broke off after it). */
    private function addConstraint(string $table, string $name, string $definition): void
    {
        $exists = DB::selectOne('SELECT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?) AS present', [$table, $name]);
        if ($exists !== null && $exists->present) {
            return;
        }
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
    }

    private function concurrently(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' && DB::connection()->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
