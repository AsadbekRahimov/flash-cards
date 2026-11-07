<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data for every environment, production included: the roles (`admin`, `teacher`) and the shop items of the game.
 * Idempotent (both seeders only add what is missing), so deploy runs it after every migration. The demo seeders
 * (Demo*, PracticeDemoSeeder) are NOT part of it and never run in production.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            GameItemSeeder::class,
        ]);
    }
}
