<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Learning steps for SM-2 (docs/06_SPACED_REPETITION.md §3).
 *
 *   state           new | learning | review | relearning
 *   learning_step   index of the current minute-based step inside (re)learning
 *   lapses          how many times a graduated (review) card was failed
 *   served_ahead_at set when the picker served a (re)learning card before its
 *                   next_review_at (learn-ahead); consumed by the next review
 *   served_ahead_token
 *                   one-time ticket issued together with served_ahead_at; the
 *                   client must echo it in POST /review (docs/06 §5.2)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->string('state', 16)->default('new')->after('is_hard');
            $table->smallInteger('learning_step')->default(0)->after('state');
            $table->smallInteger('lapses')->default(0)->after('learning_step');
            $table->timestamp('served_ahead_at')->nullable()->after('lapses');
            $table->string('served_ahead_token', 64)->nullable()->after('served_ahead_at');
        });

        // Backfill from legacy SM-2 columns:
        //  - at least one successful repetition  -> graduated (review)
        //  - answered but never passed (q < 3)    -> relearning, due at legacy next_review_at
        //    (legacy scheduled them a day ahead; once that moment is more than
        //    `learning.abandoned_after_hours` in the past they count as due for
        //    reminders / words_due_today again — docs/06 §6)
        //  - never answered                       -> new (default)
        DB::table('word_repetitions')
            ->where('repetitions', '>=', 1)
            ->update(['state' => 'review']);

        DB::table('word_repetitions')
            ->where('repetitions', '<', 1)
            ->whereNotNull('last_quality')
            ->update(['state' => 'relearning']);
    }

    public function down(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->dropColumn(['state', 'learning_step', 'lapses', 'served_ahead_at', 'served_ahead_token']);
        });
    }
};
