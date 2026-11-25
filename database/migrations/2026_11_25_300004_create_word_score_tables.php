<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The word points v2 (docs/32 §2.2–§2.6, task S2; the rules - docs/28):
 *
 *   learner_lexemes       — the state "learner × lexeme": the first confirmation of a new word, the one attempt of a rating day,
 *                           the scheduled due a repetition credit was paid for, the first long repetitions (§2.2);
 *   learner_score_periods — the category (free / pro) and the rules version of a learner's week and month, fixed ONCE at the first
 *                           credit of the period (§2.3, `ScorePeriodService::fix`);
 *   learner_score_days    — the day totals of a rating day (league zone): the raw facts and the two independent counts, weekly and
 *                           monthly, each with the category and version of its period copied in (§2.4);
 *   score_credits         — the journal: one row per credited event, kept `leagues.scoring.credits_retention_days` (§2.5);
 *   learner_score_totals  — "all time" per learner and category, recomputed from the day rows (§2.6).
 *
 * Nothing writes them yet (the core, S4, comes next); every learner FK cascades, so the final purge of a learner takes the rows
 * with it. The CHECKs (PostgreSQL only) are the last line behind the enums. `learner_lexemes` and `learner_score_days` are updated
 * in place on the review path: free room in every page (fillfactor) keeps those UPDATEs HOT, as in 2026_11_10_100000.
 * `learner_score_legacy` of §2.6 is not created: by the owner's decision of 2026-11-25 (docs/31 §5, В1) the old points are not
 * carried over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learner_lexemes', function (Blueprint $table): void {
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('lexeme_id')->constrained('lexemes');
            // The rating day of the first confirmation of the new word (NULL = not confirmed yet) and where it came from.
            $table->date('confirmed_on')->nullable();
            $table->string('confirmed_source', 8)->nullable();
            // The rating day of the last counted attempt (success, error, hint, reveal): one attempt a day.
            $table->date('last_attempt_on')->nullable();
            // The scheduled due (last review + interval) a repetition credit was already paid for: a new cycle starts after it.
            $table->timestamp('last_credit_due_at')->nullable();
            // The first long repetition credited in any count (the achievement), and in the MONTHLY count of each category (all-time ties).
            $table->date('first_long_on')->nullable();
            $table->date('long_free_on')->nullable();
            $table->date('long_pro_on')->nullable();

            $table->primary(['learner_id', 'lexeme_id']);
        });

        // "How many lexemes had a long repetition" (the achievement long_recall_25) — only the rows that had one.
        DB::statement('CREATE INDEX learner_lexemes_first_long_idx ON learner_lexemes (learner_id) WHERE first_long_on IS NOT NULL');

        Schema::create('learner_score_periods', function (Blueprint $table): void {
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('period', 5);
            // The Monday or the 1st of the period in the league zone.
            $table->date('starts_on');
            $table->string('category', 4);
            $table->smallInteger('rules_version');
            $table->timestamp('fixed_at');

            $table->primary(['learner_id', 'period', 'starts_on']);
        });

        Schema::create('learner_score_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->date('rating_day');
            $table->date('week_starts_on');
            $table->string('week_category', 4);
            $table->smallInteger('week_version');
            $table->date('month_starts_on');
            $table->string('month_category', 4);
            $table->smallInteger('month_version');
            // The facts without the limits.
            $table->integer('raw_points')->default(0);
            $table->smallInteger('new_count')->default(0);
            $table->smallInteger('review_count')->default(0);
            $table->smallInteger('long_count')->default(0);
            // The weekly count.
            $table->smallInteger('week_points')->default(0);
            $table->smallInteger('week_new')->default(0);
            $table->smallInteger('week_review')->default(0);
            $table->smallInteger('week_long')->default(0);
            $table->timestamp('week_capped_at')->nullable();
            // The monthly count.
            $table->smallInteger('month_points')->default(0);
            $table->smallInteger('month_new')->default(0);
            $table->smallInteger('month_review')->default(0);
            $table->smallInteger('month_long')->default(0);
            $table->timestamp('month_capped_at')->nullable();
            // Grows with every correction of the day (ScoreCorrectionService).
            $table->integer('revision')->default(0);
            $table->timestamps();

            $table->unique(['learner_id', 'rating_day']);
            // The monthly refresh and the clean-up read a range of days of every learner.
            $table->index('rating_day');
        });

        Schema::create('score_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->date('rating_day');
            $table->foreignId('lexeme_id')->constrained('lexemes');
            // The card that earned it; no FK: the journal outlives a word.
            $table->unsignedBigInteger('word_id');
            $table->string('source', 12);
            // training_sessions.id or game_runs.id, by `source`; no FK for the same reason.
            $table->unsignedBigInteger('source_id');
            $table->string('kind', 6);
            $table->smallInteger('week_points');
            $table->smallInteger('month_points');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            // One lexeme - one credit a rating day: the database's guarantee behind the lock of learner_lexemes.
            $table->unique(['learner_id', 'rating_day', 'lexeme_id']);
            $table->index('rating_day');
        });

        Schema::create('learner_score_totals', function (Blueprint $table): void {
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('category', 4);
            $table->integer('points')->default(0);
            $table->integer('long_words')->default(0);
            $table->integer('active_days')->default(0);
            $table->date('first_day');
            $table->date('last_day');
            $table->boolean('participant')->default(true);
            $table->timestamp('updated_at')->nullable();

            $table->primary(['learner_id', 'category']);
            // The all-time table of a category in its exact order: the top, a page after a cursor and "how many are better".
            $table->rawIndex(
                'category, participant, points desc, long_words desc, active_days desc, learner_id',
                'learner_score_totals_standings_idx',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE learner_lexemes SET (fillfactor = 90)');
            DB::statement('ALTER TABLE learner_score_days SET (fillfactor = 70)');

            DB::statement("ALTER TABLE learner_lexemes ADD CONSTRAINT learner_lexemes_confirmed_source_check CHECK (confirmed_source IN ('training','game','legacy'))");
            DB::statement("ALTER TABLE learner_score_periods ADD CONSTRAINT learner_score_periods_period_check CHECK (period IN ('week','month'))");
            DB::statement("ALTER TABLE learner_score_periods ADD CONSTRAINT learner_score_periods_category_check CHECK (category IN ('free','pro'))");
            DB::statement("ALTER TABLE learner_score_days ADD CONSTRAINT learner_score_days_category_check CHECK (week_category IN ('free','pro') AND month_category IN ('free','pro'))");
            DB::statement('ALTER TABLE learner_score_days ADD CONSTRAINT learner_score_days_points_check CHECK (raw_points >= 0 AND week_points >= 0 AND month_points >= 0)');
            DB::statement("ALTER TABLE score_credits ADD CONSTRAINT score_credits_source_check CHECK (source IN ('training','group_lesson','game'))");
            DB::statement("ALTER TABLE score_credits ADD CONSTRAINT score_credits_kind_check CHECK (kind IN ('new','review','long'))");
            DB::statement('ALTER TABLE score_credits ADD CONSTRAINT score_credits_points_check CHECK (week_points >= 0 AND month_points >= 0)');
            DB::statement("ALTER TABLE learner_score_totals ADD CONSTRAINT learner_score_totals_category_check CHECK (category IN ('free','pro'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learner_score_totals');
        Schema::dropIfExists('score_credits');
        Schema::dropIfExists('learner_score_days');
        Schema::dropIfExists('learner_score_periods');
        Schema::dropIfExists('learner_lexemes');
    }
};
