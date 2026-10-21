<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/13 §1.5 — the A/B bucket of the spaced-repetition algorithm (`sm2` / `fsrs`), fixed when the
 * learner is created; NULL = the platform default `config('learning.algorithm')` (2D.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('scheduler', 8)->nullable()->after('streak_freezes_refilled_on');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn('scheduler');
        });
    }
};
