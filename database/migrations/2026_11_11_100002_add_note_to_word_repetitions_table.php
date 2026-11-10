<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/25 §3.4, docs/06 §12.3 — the learner's own memory hook for a word ("my hint"): plain text, at most 200
 * characters, on the (learner, word) row itself, so the card reads it with the repetition and no extra statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->string('note', 200)->nullable()->after('scheduled_days');
        });
    }

    public function down(): void
    {
        Schema::table('word_repetitions', function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
