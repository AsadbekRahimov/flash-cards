<?php

declare(strict_types=1);

use App\Http\Middleware\RequireOrgTwoFactor;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->seed(RolePermissionSeeder::class);
});

function twoFactorAdmin(): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
        'two_factor_recovery_codes' => ['r1'],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('admin');

    return $user;
}

it('keeps both 2FA middlewares in the Livewire persistent list', function (): void {
    $admin = twoFactorAdmin();

    $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => time(), '2fa.web.user_id' => $admin->id])
        ->get('/admin')
        ->assertOk();

    $list = app(PersistentMiddleware::class)->getPersistentMiddleware();

    expect($list)->toContain(RequireTwoFactor::class)
        ->and($list)->toContain(RequireOrgTwoFactor::class);
});

it('rejects a Livewire update once the 2FA mark is gone from the session', function (): void {
    $admin = twoFactorAdmin();

    $html = $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => time(), '2fa.web.user_id' => $admin->id])
        ->get('/admin/users')
        ->assertOk()
        ->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $m);
    expect($m)->toHaveKey(1);
    $snapshot = html_entity_decode($m[1]);

    session()->forget(['2fa.web.passed_at', '2fa.web.user_id']);

    $response = $this->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true']);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toEndWith('/2fa/challenge?guard=web');
});
