<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/13 §1.6 — `training_reviews` learns which exercise the grade came from (2D.2) and, for the
 * FSRS A/B of 2D.3/2D.4, which scheduler applied it and the interval the card had before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_reviews', function (Blueprint $table): void {
            $table->string('exercise', 12)->default('flash')->after('quality');
            $table->string('answer_text', 100)->nullable()->after('exercise');
            $table->string('scheduler', 8)->default('sm2')->after('answer_text');
            $table->smallInteger('interval_before_days')->nullable()->after('scheduler');
        });
    }

    public function down(): void
    {
        Schema::table('training_reviews', function (Blueprint $table): void {
            $table->dropColumn(['exercise', 'answer_text', 'scheduler', 'interval_before_days']);
        });
    }
};
