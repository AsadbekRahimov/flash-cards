<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/31 §5а, E1 - the emoji a Pro learner shows next to the name: the CODE of one emoji of config/pro_emoji.php (never the emoji
 * itself, never free text), NULL = none chosen. Kept when Pro ends and shown again when it comes back. No index: only the learner's
 * own row reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('pro_emoji', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropColumn('pro_emoji');
        });
    }
};
