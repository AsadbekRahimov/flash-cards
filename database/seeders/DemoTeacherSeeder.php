<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoTeacherSeeder extends Seeder
{
    public function run(): void
    {
        $teacher = User::updateOrCreate(
            ['email' => 'teacher@local'],
            [
                'name' => 'Demo Teacher',
                'password' => Hash::make('password'),
                'telegram_user_id' => 100_000_001,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $teacher->syncRoles(['teacher']);
    }
}
