<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QueryLog;

uses(RefreshDatabase::class);

it('measures', function (): void {
    $this->seed(Database\Seeders\RolePermissionSeeder::class);
    $user = App\Models\User::factory()->create(['is_active' => true, 'two_factor_secret' => 'stub', 'two_factor_recovery_codes' => [], 'two_factor_confirmed_at' => now()]);
    $user->assignRole('admin');
    $get = fn (string $q = '') => $this->actingAs($user)->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])->get('/admin'.$q);
    $get()->assertOk();
    $q = QueryLog::during(fn () => $get()->assertOk());
    $r = $get();
    fwrite(STDERR, "\nQUERIES ".count($q)."\n".implode("\n", $q)."\nBYTES ".strlen((string) $r->getContent())."\n");
});
