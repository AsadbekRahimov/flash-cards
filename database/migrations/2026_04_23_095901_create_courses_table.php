<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('owner_type', 16)->default('platform');
            // Polymorphic owner (organizations.id when owner_type = 'organization'): no FK by design.
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->jsonb('title');
            $table->jsonb('description')->default('{}');
            $table->string('target_language', 5)->default('en');
            $table->string('level', 2)->nullable();
            $table->string('exam_tag', 16)->nullable();
            $table->string('visibility', 10)->default('public');
            $table->string('status', 10)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->integer('words_count')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestamps();

            $table->index(['status', 'visibility', 'position']);
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
