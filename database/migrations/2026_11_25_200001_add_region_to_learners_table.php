<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/35 §2.3, R1 - the region of a learner: an ISO 3166-2:UZ code or `other` (App\Domain\Learners\Support\Regions), NULL = not
 * given. No CHECK: the list lives in config/regions.php and the service checks it. `region_changed_at` is the moment of the last
 * accepted choice of a region (the 30-day rule of App\Domain\Learners\Services\LearnerRegionService). The index serves the filter of
 * `/admin` and, later, the region tables (R6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->string('region_code', 8)->nullable();
            $table->timestamp('region_changed_at')->nullable();
            $table->index('region_code', 'learners_region_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('learners', function (Blueprint $table): void {
            $table->dropIndex('learners_region_code_idx');
            $table->dropColumn(['region_code', 'region_changed_at']);
        });
    }
};
