<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('allows admin users to reach /admin when 2FA is confirmed', function (): void {
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => auth()->id()])
        ->get('/admin')
        ->assertOk();
});

it('allows active teachers to reach their scoped panel', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $this->actingAs($teacher)
        ->get('/admin')
        ->assertOk();
});

it('forbids users without a panel role from /admin', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

it('forbids inactive admins from /admin', function (): void {
    $admin = User::factory()->create(['is_active' => false]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/admin')
        ->assertForbidden();
});

it('redirects guests from /admin to login', function (): void {
    $this->get('/admin')
        ->assertRedirect('/admin/login');
});
