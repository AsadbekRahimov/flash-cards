<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
    }
};
