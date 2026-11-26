<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per recipient of a poll (docs/35 §1.4, O2): inserted at launch by one `INSERT … SELECT … ON CONFLICT DO NOTHING`, then
 * captured `pending → sending` and finished (`sent`, `skipped`, `forbidden`, `failed`, `unknown`) by the broadcast, then written by
 * the taps (`answered_at`, `draft`, `changed_count`). A test send to an administrator has `is_test = true` and no learner.
 *
 * Big (polls × audience) and hot on write while a broadcast runs, so only the indexes real queries need (docs/35 §1.5):
 *  - `token` unique - every tap finds its row by the token of the button;
 *  - unique `(poll_id, learner_id)` - one delivery per learner and poll, the conflict target of the launch insert. NOT partial:
 *    NULLs are distinct in a unique index (PostgreSQL and sqlite), so the test rows (`learner_id` NULL) never collide, as with
 *    `WHERE learner_id IS NOT NULL` - but the index also holds the test rows and serves every "all rows of a poll" read: the
 *    counts of the results page, the CSV, `polls:prune`, the cascade of a poll's delete;
 *  - `(learner_id)` - the final purge of a learner cascades here; without it every purge reads the whole table;
 *  - partial `(poll_id, id) WHERE status IN ('pending','sending')` - the batches of the launch, "is anything left" before
 *    `sending → sent`, and `polls:sweep` (rows stuck in `sending`). Only the unsent rows: it stays small, a finished row is
 *    not added to it again. The status itself is not indexed in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('learner_id')->nullable()->constrained('learners')->cascadeOnDelete();
            // Where the message went; the `from.id` of a tap must be this one.
            $table->bigInteger('telegram_user_id');
            $table->boolean('is_test')->default(false);
            // `Str::random(12)`: the token of the buttons `pl1:<token>:<n>`.
            $table->char('token', 12);
            // The language the message went out in (the learner's `interface_locale` at the insert).
            $table->string('locale', 5);
            $table->string('status', 10)->default('pending');
            $table->bigInteger('message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error_code', 40)->nullable();
            // Ticked but not yet submitted options of a multiple-answer poll (poll_options ids).
            $table->jsonb('draft')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->smallInteger('changed_count')->default(0);

            $table->unique('token');
            $table->unique(['poll_id', 'learner_id']);
            $table->index('learner_id');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE INDEX poll_deliveries_unsent_idx ON poll_deliveries (poll_id, id) WHERE status IN ('pending','sending')");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE poll_deliveries ADD CONSTRAINT poll_deliveries_status_check CHECK (status IN ('pending','sending','sent','skipped','forbidden','failed','unknown'))");
            // A test send has no learner, a real delivery always has one (it goes away with the learner, never becomes NULL).
            DB::statement('ALTER TABLE poll_deliveries ADD CONSTRAINT poll_deliveries_learner_check CHECK (is_test = (learner_id IS NULL))');
            DB::statement('ALTER TABLE poll_deliveries ADD CONSTRAINT poll_deliveries_changed_count_check CHECK (changed_count >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_deliveries');
    }
};
