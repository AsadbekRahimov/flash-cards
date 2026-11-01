<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Practice pilot (docs/16 §2.1, §3.2): `learners.practice_pilot_at` marks a learner who is in the pilot of
 * the practice (`PRACTICE_ACCESS=pilot`). The admin sets / clears it on the learner's card. NULL = not in the
 * pilot. No index: the flag is rare, and with `access = all` the column is not read at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->timestamp('practice_pilot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn('practice_pilot_at');
        });
    }
};
