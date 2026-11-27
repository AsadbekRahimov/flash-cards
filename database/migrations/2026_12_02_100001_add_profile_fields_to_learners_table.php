<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/40 §2.1 - what the onboarding of the Mini App asks besides the columns the bot already has:
 *  - `display_name` the name the learner typed (App\Domain\Cup\Support\DisplayName rules: up to 32 graphemes and 64 code points,
 *                   hence 64 - a PostgreSQL varchar counts code points); NULL = `first_name` (the Telegram snapshot) is shown.
 *                   Shown only on the learner's own screens (docs/40 §3, В1);
 *  - `age_range`    `lt14 | 14_17 | 18_24 | 25_34 | 35_44 | 45p` - only stored, shown to nobody (В5);
 *  - `goals`        the reasons to learn English, 1-6 of `career | study | travel | relocation | communication | media` (jsonb).
 * No index: nothing filters by them on a hot path. The exam-tag `goal` of the bot stays as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('display_name', 64)->nullable()->after('username');
            $table->string('age_range', 8)->nullable()->after('level');
            $table->jsonb('goals')->nullable()->after('age_range');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'age_range', 'goals']);
        });
    }
};
