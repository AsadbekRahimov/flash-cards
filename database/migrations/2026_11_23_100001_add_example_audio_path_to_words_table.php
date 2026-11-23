<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/26 §5.7, §9.11 - the pre-generated audio of the anchor example (`words.example`), the sentence the answer panel shows until
 * the card graduates. Content-addressed like `audio_path` (App\Domain\Content\Services\WordAudioService::examplePathFor): the
 * example is voiced only while this path is the one its current text hashes to. No index: nobody looks a word up by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->string('example_audio_path', 255)->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table): void {
            $table->dropColumn('example_audio_path');
        });
    }
};
