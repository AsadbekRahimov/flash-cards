<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff of an organization (docs/13 §1.3): who may open the `/org` cabinet and with which role.
 * Tenant roles live here, not in spatie roles — one user can be `owner` of one center and
 * `teacher` of another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 12); // owner | teacher
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_users');
    }
};
