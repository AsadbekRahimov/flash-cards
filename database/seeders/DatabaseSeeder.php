<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DemoAdminSeeder::class,
            DemoTeacherSeeder::class,
            DemoContentSeeder::class,
            DemoGroupSeeder::class,
            DemoLearnersSeeder::class,
            DemoRepetitionsSeeder::class,
            DemoActivitySeeder::class,
            // After the content and the repetitions: it imports the pilot package of the practice into the demo course and
            // introduces the demo learners to its words.
            PracticeDemoSeeder::class,
        ]);
    }
}
