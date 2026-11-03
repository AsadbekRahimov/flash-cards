<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The progress on the map of the game (docs/18 §12.2.3, §12.2.4): `game_stop_progress` (a lesson), `game_city_progress`
 * (a module) and `game_word_misses` (the mistakes of the game per word: the rule of docs/18 §12.4 and the pick of a boss).
 *
 * Whether a stop is OPEN is not stored: it is derived from `word_repetitions` in one query per map.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_stop_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete(); // denormalized: the map and the friends in one query
            $table->foreignId('stage_id')->constrained('stages')->cascadeOnDelete();
            $table->smallInteger('stars')->default(0); // the best result, 0..3
            $table->integer('best_score')->default(0);
            $table->smallInteger('runs_count')->default(0); // completed + failed runs
            $table->timestamp('first_cleared_at')->nullable(); // the first run with >= 1 star
            $table->timestamp('last_run_at');
            $table->timestamps();

            $table->unique(['learner_id', 'lesson_id']);
            $table->index(['learner_id', 'course_id']);
            $table->index(['course_id', 'stage_id', 'learner_id']); // the friends on the map
        });

        Schema::create('game_city_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('stages')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->smallInteger('boss_attempts')->default(0);
            $table->timestamp('boss_defeated_at')->nullable(); // the first win: the city is open
            $table->smallInteger('boss_best_accuracy')->default(0); // 0..100
            $table->smallInteger('boss_wins')->default(0);
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'stage_id']);
            $table->index(['learner_id', 'course_id']);
        });

        Schema::create('game_word_misses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->foreignId('word_id')->constrained('words')->cascadeOnDelete();
            $table->integer('misses')->default(0); // wrong / skipped / with a hint, in the game, in total
            $table->timestamp('last_missed_at');
            $table->timestamp('last_pulled_at')->nullable(); // when the game last really moved `next_review_at` closer
            $table->timestamps();

            $table->unique(['learner_id', 'word_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE game_stop_progress ADD CONSTRAINT game_stop_progress_stars_check CHECK (stars BETWEEN 0 AND 3)');
            DB::statement('ALTER TABLE game_city_progress ADD CONSTRAINT game_city_progress_accuracy_check CHECK (boss_best_accuracy BETWEEN 0 AND 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_word_misses');
        Schema::dropIfExists('game_city_progress');
        Schema::dropIfExists('game_stop_progress');
    }
};
