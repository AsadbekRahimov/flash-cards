<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 64)->unique();
            $table->string('type', 16)->default('center');
            $table->string('status', 16)->default('active');
            $table->string('timezone', 64)->default('Asia/Tashkent');
            $table->string('locale', 5)->default('ru');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
