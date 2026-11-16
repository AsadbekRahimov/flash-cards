<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly rating with prizes (docs/13 §7.6, docs/03 §2.62–2.64): one row per calendar month of the league time zone
 * (`league_months`), the materialised points of every learner with activity in it (`league_month_scores`, refreshed
 * outside the review transaction) and the candidates frozen at the close for the administrator's review
 * (`league_month_candidates`: confirmed winners, disqualifications, "prize delivered").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_months', function (Blueprint $table): void {
            $table->id();
            $table->date('starts_on')->unique();
            $table->date('ends_on');
            // open → closing → pending_review → confirmed
            $table->string('status', 16)->default('open');
            // [{place, ru, uz}, …]; NULL = the defaults of config('leagues.monthly.prizes'); [] = no prizes this month.
            $table->jsonb('prizes')->nullable();
            $table->integer('participants_count')->default(0);
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('league_month_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_month_id')->constrained('league_months')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            // The rating points: the day points capped by `leagues.monthly.daily_cap`, summed over the month.
            $table->integer('points')->default(0);
            // The same sum without the cap (the weekly formula as is).
            $table->integer('raw_points')->default(0);
            $table->smallInteger('active_days')->default(0);
            $table->smallInteger('capped_days')->default(0);
            $table->integer('max_day_points')->default(0);
            $table->integer('reviews')->default(0);
            $table->integer('correct')->default(0);
            $table->integer('new_words')->default(0);
            $table->smallInteger('goal_days')->default(0);
            $table->integer('game_points')->default(0);
            $table->bigInteger('time_spent_ms')->default(0);
            // In the rating: not deleted, not blocked, onboarded, not opted out of the leagues (computed at the refresh).
            $table->boolean('participant')->default(true);
            // Written once by the close (participants only); the order of the rating: points, active days, older account.
            $table->integer('final_rank')->nullable();
            $table->timestamps();

            $table->unique(['league_month_id', 'learner_id']);
            // The top list and "how many are ahead of me" — the exact order of the rating.
            $table->rawIndex('league_month_id, participant, points desc, active_days desc, learner_id', 'league_month_scores_standings_idx');
        });

        Schema::create('league_month_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('league_month_id')->constrained('league_months')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            // The place among the eligible participants at the close (1 = best); the final `place` is given at the confirmation.
            $table->integer('rank');
            $table->integer('points');
            $table->smallInteger('active_days');
            $table->jsonb('signals')->default('{}');
            // candidate → winner (confirmed with a place) | disqualified
            $table->string('status', 16)->default('candidate');
            $table->smallInteger('place')->nullable();
            $table->timestamp('disqualified_at')->nullable();
            $table->foreignId('disqualified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disqualify_reason', 500)->nullable();
            $table->timestamp('prize_delivered_at')->nullable();
            $table->foreignId('prize_delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['league_month_id', 'learner_id']);
            $table->index(['league_month_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_month_candidates');
        Schema::dropIfExists('league_month_scores');
        Schema::dropIfExists('league_months');
    }
};
