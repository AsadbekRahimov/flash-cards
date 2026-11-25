<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The friends' cup of a Telegram group (docs/33 §2.2-§2.4, §2.7, §2.8; K4): `cups`, `cup_participants`, `cup_days`, `cup_day_scores`,
 * `cup_messages`.
 *
 * Indexes serve only the queries of the code (docs/33 §11 "решения реализации"): the scheduler reads the open cups through three
 * PARTIAL indexes (a few rows each, never the finished history); the table of a cup is read through the unique `(cup_id, learner_id)`
 * and sorted in memory (a cup has tens of participants), so the scores `best_*` that change with every right answer are in NO index
 * and their updates stay HOT (fillfactor 90). Every foreign key has an index on its side, except `cups.course_id` (RESTRICT: a course
 * with cups is not deleted; the check reads a table of thousands of rows).
 *
 * "One active cup per chat" is a database fact: `active_group_id` (= `telegram_group_id` while draft / scheduled / running, NULL after)
 * is UNIQUE, and on PostgreSQL a CHECK ties it to the status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('active_group_id')->nullable()->unique();
            $table->string('public_code', 22)->unique();
            $table->string('status', 10); // draft | scheduled | running | finished | canceled
            $table->string('cancel_reason', 24)->nullable();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('level', 2);
            $table->string('timezone', 64);
            $table->smallInteger('days');
            $table->smallInteger('counted_days');
            $table->smallInteger('round_tasks');
            $table->smallInteger('duel_tasks');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('summaries_enabled')->default(true);
            $table->smallInteger('summary_minute')->default(1200);
            $table->string('locale', 5);
            $table->string('seed_secret', 64);
            $table->jsonb('word_plan')->nullable();
            $table->unsignedBigInteger('proposed_by_telegram_user_id');
            $table->unsignedBigInteger('confirmed_by_telegram_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->bigInteger('card_chat_id')->nullable();
            $table->bigInteger('card_message_id')->nullable();
            $table->integer('card_thread_id')->nullable();
            $table->string('card_text_hash', 40)->nullable();
            $table->timestamp('card_rendered_at')->nullable();
            $table->string('summary_scores_hash', 40)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['telegram_group_id', 'created_at']); // the history of a chat; the index of the FK
        });

        Schema::create('cup_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('display_name', 32);
            $table->string('status', 8); // active | left
            $table->timestamp('joined_at');
            $table->smallInteger('first_day');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('membership_checked_at')->nullable();
            $table->smallInteger('best_total')->default(0);
            $table->smallInteger('best_round_points')->default(0);
            $table->smallInteger('days_played')->default(0);
            $table->smallInteger('final_rank')->nullable();
            $table->smallInteger('rename_count')->default(0);
            $table->timestamps();

            $table->unique(['cup_id', 'learner_id']); // one row per learner; the table of the cup; the index of the FK cup_id
            $table->index('learner_id'); // "my cups"; the cascade of a learner
        });

        Schema::create('cup_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->smallInteger('day');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->jsonb('snapshot')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('summary_posted_at')->nullable();
            $table->timestamps();

            $table->unique(['cup_id', 'day']);
        });

        Schema::create('cup_day_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('cup_participants')->cascadeOnDelete();
            $table->smallInteger('day');
            $table->smallInteger('round_points')->default(0);
            $table->smallInteger('duel_points')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['participant_id', 'day']);
            // "new results since the card" (K6) and the cascade of a cup; NOT (cup_id, updated_at): updated_at changes with every point
            $table->index('cup_id');
        });

        Schema::create('cup_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cup_id')->constrained('cups')->cascadeOnDelete();
            $table->string('dedupe_key', 40);
            $table->string('kind', 10); // card | summary | final | duel | notice
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('message_id')->nullable();
            $table->bigInteger('thread_id')->nullable();
            $table->string('status', 10)->default('pending'); // pending | sending | sent | skipped | unknown | failed
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamps();

            $table->unique(['cup_id', 'dedupe_key']);
        });

        // The scheduler (cups:advance, cups:send-outbox): only the rows still open are in these indexes.
        DB::statement("CREATE INDEX cups_draft_created_idx ON cups (created_at) WHERE status = 'draft'");
        DB::statement("CREATE INDEX cups_scheduled_start_idx ON cups (starts_at) WHERE status = 'scheduled'");
        DB::statement("CREATE INDEX cups_running_end_idx ON cups (ends_at) WHERE status = 'running'");
        DB::statement('CREATE INDEX cup_days_unbuilt_idx ON cup_days (starts_at) WHERE built_at IS NULL');
        DB::statement("CREATE INDEX cup_messages_outbox_idx ON cup_messages (created_at) WHERE status IN ('pending', 'sending')");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cups ADD CONSTRAINT cups_status_check CHECK (status IN ('draft','scheduled','running','finished','canceled'))");
            DB::statement(
                "ALTER TABLE cups ADD CONSTRAINT cups_active_shape_check
                 CHECK ((status IN ('draft','scheduled','running')) = (active_group_id IS NOT NULL))",
            );
            DB::statement(
                "ALTER TABLE cups ADD CONSTRAINT cups_cancel_shape_check
                 CHECK ((status = 'canceled') = (cancel_reason IS NOT NULL)
                    AND (cancel_reason IS NULL OR cancel_reason IN ('draft_expired','chat_admin','platform_admin','no_players')))",
            );
            DB::statement(
                'ALTER TABLE cups ADD CONSTRAINT cups_sizes_check
                 CHECK (days BETWEEN 1 AND 31 AND counted_days BETWEEN 1 AND days AND round_tasks BETWEEN 1 AND 10
                    AND duel_tasks BETWEEN 0 AND 5 AND summary_minute BETWEEN 0 AND 1439)',
            );
            DB::statement(
                "ALTER TABLE cups ADD CONSTRAINT cups_schedule_shape_check
                 CHECK (status = 'draft' OR status = 'canceled' OR (starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at))",
            );

            DB::statement("ALTER TABLE cup_participants ADD CONSTRAINT cup_participants_status_check CHECK (status IN ('active','left'))");
            DB::statement(
                'ALTER TABLE cup_participants ADD CONSTRAINT cup_participants_numbers_check
                 CHECK (first_day >= 1 AND best_total BETWEEN 0 AND 775 AND best_round_points BETWEEN 0 AND best_total
                    AND days_played >= 0 AND rename_count >= 0)',
            );
            DB::statement('ALTER TABLE cup_days ADD CONSTRAINT cup_days_shape_check CHECK (day >= 1 AND ends_at > starts_at)');
            DB::statement(
                'ALTER TABLE cup_day_scores ADD CONSTRAINT cup_day_scores_range_check
                 CHECK (day >= 1 AND round_points BETWEEN 0 AND 20 AND duel_points BETWEEN 0 AND 5)',
            );
            DB::statement("ALTER TABLE cup_messages ADD CONSTRAINT cup_messages_kind_check CHECK (kind IN ('card','summary','final','duel','notice'))");
            DB::statement(
                "ALTER TABLE cup_messages ADD CONSTRAINT cup_messages_status_check
                 CHECK (status IN ('pending','sending','sent','skipped','unknown','failed'))",
            );

            // The scores of a participant change with every right answer: room on the page keeps those updates HOT.
            DB::statement('ALTER TABLE cup_participants SET (fillfactor = 90)');
            DB::statement('ALTER TABLE cup_day_scores SET (fillfactor = 80)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cup_messages');
        Schema::dropIfExists('cup_day_scores');
        Schema::dropIfExists('cup_days');
        Schema::dropIfExists('cup_participants');
        Schema::dropIfExists('cups');
    }
};
