<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stage_id')->constrained('stages')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 255)->nullable();
            $table->timestamps();

            $table->unique(['stage_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
