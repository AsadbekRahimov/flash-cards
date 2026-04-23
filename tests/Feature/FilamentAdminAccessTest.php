<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('allows admin users to reach /admin', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk();
});

it('forbids users without admin role from /admin', function (): void {
    $teacher = User::factory()->create(['is_active' => true]);
    $teacher->assignRole('teacher');

    $this->actingAs($teacher)
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
