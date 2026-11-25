<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Polls in the bot (docs/35 §1.4, O2): one row per poll made in `/admin`. A photo with a caption (or a text) and inline buttons, our
 * own count of the votes - not a native Telegram poll (§1.3). The texts are `{ru, uz}`; `image_file_id` is Telegram's id of the
 * picture after its first successful send, every later send goes by it without an upload. `results_snapshot` keeps the totals at
 * closing: the shares do not move when a learner's rows go with the final purge.
 *
 * A small table (tens of rows a year), written a few times per poll. The one index is for `polls:close-due` every 5 minutes:
 * only the open polls with a deadline. The two user FKs have no index on purpose - only a delete of a user reads them (a seq scan of
 * this small table), and the poll survives it (`nullOnDelete`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->jsonb('question');
            $table->string('image_path', 255)->nullable();
            $table->string('image_file_id', 255)->nullable();
            $table->boolean('allows_multiple')->default(false);
            $table->smallInteger('max_choices')->nullable();
            $table->boolean('allow_change')->default(true);
            $table->jsonb('audience');
            $table->string('status', 10)->default('draft');
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('launched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('results_snapshot')->nullable();
            $table->timestamps();
        });

        // `polls:close-due`: the open polls whose `closes_at` has come. Rows leave it at closing; a poll without a deadline is never in it.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE INDEX polls_close_due_idx ON polls (closes_at) WHERE status IN ('sending','sent') AND closes_at IS NOT NULL");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE polls ADD CONSTRAINT polls_status_check CHECK (status IN ('draft','sending','sent','closed','cancelled'))");
            DB::statement('ALTER TABLE polls ADD CONSTRAINT polls_max_choices_check CHECK (max_choices IS NULL OR max_choices BETWEEN 1 AND 10)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('polls');
    }
};
