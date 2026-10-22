<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * Demo seeders create known credentials (admin@local / password) and fake data: they run only in
 * local/testing or when `APP_DEMO_SEED=true` (config `app.demo_seed`) is set explicitly.
 */
trait GuardsDemoSeeding
{
    protected function demoSeedingAllowed(): bool
    {
        if (app()->environment(['local', 'testing']) || config('app.demo_seed') === true) {
            return true;
        }

        // Seeder::$command is documented non-null but stays unset when a seeder is run without
        // `db:seed` (e.g. app(DatabaseSeeder::class)->run() in tests).
        if (isset($this->command)) { // @phpstan-ignore isset.property
            $this->command->warn(static::class.' skipped: demo seeders are disabled outside local/testing (set APP_DEMO_SEED=true to force).');
        }

        return false;
    }
}
