<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\GuardsDemoSeeding;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAdminSeeder extends Seeder
{
    use GuardsDemoSeeding;

    public function run(): void
    {
        if (! $this->demoSeedingAllowed()) {
            return;
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@local'],
            [
                'name' => 'Demo Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles(['admin']);
    }
}
