<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit membership of a learner in an organization and its seat (docs/13 §1.3, §6.1).
 *
 * A seat = `status = active AND seat_state = seated`; only seated members of an active
 * organization with a live subscription get the sponsored Pro (EntitlementResolver).
 * `waitlisted` = no seat left (FIFO by `waitlisted_at` when one frees up); `none` when the
 * membership is not active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('learner_id')->constrained('learners')->cascadeOnDelete();
            $table->string('status', 12); // active | left | removed
            $table->string('seat_state', 12); // seated | waitlisted | none
            $table->string('joined_via', 12); // invite | group | admin
            $table->foreignId('invite_id')->nullable()->constrained('organization_invites')->nullOnDelete();
            $table->timestamp('seated_at')->nullable();
            $table->timestamp('waitlisted_at')->nullable();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->unique(['organization_id', 'learner_id']);
            $table->index(['organization_id', 'status', 'seat_state']);
            $table->index(['learner_id', 'status']);
            $table->index(['organization_id', 'seat_state', 'waitlisted_at'], 'organization_members_waitlist_fifo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_members');
    }
};
