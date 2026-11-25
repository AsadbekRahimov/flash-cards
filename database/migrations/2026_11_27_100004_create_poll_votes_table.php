<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The votes (docs/35 §1.4, O2): one row per chosen option of a delivery; a change of the answer replaces the delivery's rows
 * (`DELETE` + `INSERT`). `poll_id` is copied in for the count of the results page.
 *
 * Indexes - only these two:
 *  - unique `(poll_delivery_id, poll_option_id)` - one vote per option; the `DELETE` of a delivery's votes and the cascade of a
 *    delivery (and so of a learner) go through it;
 *  - `(poll_id, poll_option_id)` - the count `GROUP BY poll_option_id` of a poll, the CSV, the cascade of a poll's delete.
 * `poll_option_id` has no index of its own: an option is deleted only with its poll (covered above) or while the poll is a draft
 * (the form, O6), where at most the administrators' test votes exist - a rare seq scan instead of a write on every vote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('poll_delivery_id')->constrained('poll_deliveries')->cascadeOnDelete();
            $table->foreignId('poll_option_id')->constrained('poll_options')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['poll_delivery_id', 'poll_option_id']);
            $table->index(['poll_id', 'poll_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
    }
};
