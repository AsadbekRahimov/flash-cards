<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The answers of a poll (docs/35 §1.4, O2): 2-10 per poll, `position` 0..9 is the `n` of the button `pl1:<token>:<n>`. The label
 * is `{ru, uz}`, at most 40 characters (checked by the form, O6). The unique `(poll_id, position)` is also the index of the FK:
 * the options of a poll are always read by `poll_id`, and the poll's delete cascades through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->jsonb('label');

            $table->unique(['poll_id', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE poll_options ADD CONSTRAINT poll_options_position_check CHECK (position BETWEEN 0 AND 9)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_options');
    }
};
