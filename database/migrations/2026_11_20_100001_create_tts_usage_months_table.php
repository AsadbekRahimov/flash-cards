<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly character budget of the Google TTS driver (docs/26 §5.9, §9.3): one row per billing month (Pacific time),
 * reserved before every call with one conditional UPDATE (App\Domain\Content\Services\Tts\TtsCharacterBudget). No model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_usage_months', function (Blueprint $table): void {
            $table->char('month', 7)->primary(); // '2026-11'
            $table->unsignedBigInteger('characters')->default(0);
            $table->unsignedInteger('requests')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_usage_months');
    }
};
