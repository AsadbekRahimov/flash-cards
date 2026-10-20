<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invite codes of an organization (docs/13 §1.3): deep-link `t.me/<bot>?start=org_<code>`.
 * `telegram_group_id` is a label only ("group 7B") — joining the chat is done by the person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->string('label', 64)->nullable();
            $table->foreignId('telegram_group_id')->nullable()->constrained('telegram_groups')->nullOnDelete();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invites');
    }
};
