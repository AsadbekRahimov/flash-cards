<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/13 §1.6 — FSRS memory state next to the SM-2 columns (docs/06 §9, 2D.3):
 *
 *   stability       S, days (FSRS-5); NULL until the card is scheduled by FSRS
 *   difficulty      D, 1..10; NULL until the card is scheduled by FSRS
 *   scheduler       which algorithm wrote the row last (`sm2` / `fsrs`)
 *   scheduled_days  the interval FSRS assigned (metrics); 0 on a lapse
 *
 * The SM-2 columns stay untouched by FSRS, so a learner can be switched back without loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->decimal('stability', 9, 4)->nullable()->after('served_ahead_token');
            $table->decimal('difficulty', 6, 4)->nullable()->after('stability');
            $table->string('scheduler', 8)->default('sm2')->after('difficulty');
            $table->smallInteger('scheduled_days')->nullable()->after('scheduler');
        });
    }

    public function down(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->dropColumn(['stability', 'difficulty', 'scheduler', 'scheduled_days']);
        });
    }
};
