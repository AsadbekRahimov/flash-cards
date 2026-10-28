<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops `word_repetitions_due_learners_idx (next_review_at, learner_id)`, created by 2026_05_03_000001; docs/03 §2.8.
 *
 * It was meant for a "batch scan of the learners who should be reminded". That scan is `learners.next_reminder_at`
 * (`learners_next_reminder_at_idx`, ReminderDispatcher), and no query of the app filters `word_repetitions` by
 * `next_review_at` WITHOUT a learner: the three statements that filter by it (CardPicker's due pick and learn-ahead pick,
 * the `dueCount` behind /today, /me and the reminders) carry `learner_id = ?` and are served by
 * `word_repetitions_next_card_idx (learner_id, is_hard DESC, next_review_at, id)` and
 * `(learner_id, next_review_at)`. Checked on PostgreSQL 16 with 900 000 rows (300 learners x 3 000 words): EXPLAIN
 * (ANALYZE, BUFFERS) of the statements of every real path (/me, /today, /courses, /league, /friends, /achievements,
 * /assignments, a daily and a lesson session with their reviews, CardPicker, LearnerStatsService, the catalog, the exam
 * lookup, the Filament learner list) and the generic plans of the 163 statements the test suite issues against the
 * table show it in no plan, `idx_scan` stayed 0 for all of them, and the plans of the three statements are the same
 * with the index dropped. It only costs: 36 MB at that size, and a new entry in it with every review (each `POST /review`
 * moves `next_review_at`).
 *
 * What it could serve is a query over ALL learners by `next_review_at` ("who has a card due in the next 15 minutes":
 * 0.05 ms against 21 ms without it, warm). Nobody issues such a query; whoever adds one brings the index back in the
 * same change (`down()` rebuilds it exactly).
 *
 * Like 2026_10_28_000001 and _200001, PostgreSQL drops it `CONCURRENTLY` (a plain DROP takes an ACCESS EXCLUSIVE lock that
 * every `POST /review` would queue behind), which cannot run inside a transaction block, hence `$withinTransaction =
 * false`. Inside a transaction of the caller (the test suite runs `migrate:fresh` / `migrate:rollback` in RefreshDatabase)
 * the plain statement is used. sqlite has the index too (the table migration creates it on every driver) and drops it
 * with the same plain statement.
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
        $this->dropIndex('word_repetitions_due_learners_idx');
    }

    public function down(): void
    {
        // A leftover INVALID index from an interrupted CONCURRENTLY build would make the rebuild a no-op: clear it first.
        $this->dropIndex('word_repetitions_due_learners_idx');

        DB::statement(
            "CREATE INDEX {$this->concurrently()}word_repetitions_due_learners_idx
             ON word_repetitions (next_review_at, learner_id)",
        );
    }

    private function dropIndex(string $name): void
    {
        DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
    }

    private function concurrently(): string
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'pgsql' && $connection->transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
