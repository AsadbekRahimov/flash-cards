<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('plan', 16);
            $table->smallInteger('duration_days');
            $table->integer('max_redemptions')->nullable();
            $table->integer('redemptions_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index('created_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
