<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

function adminUser(array $attrs = []): User
{
    $user = User::factory()->create(array_merge([
        'is_active' => true,
    ], $attrs));
    $user->assignRole('admin');

    return $user;
}

it('redirects admin without 2FA from /admin to /2fa/setup', function (): void {
    $this->actingAs(adminUser())
        ->get('/admin')
        ->assertRedirect(route('2fa.setup'));
});

it('lets admin finish 2FA setup with a valid TOTP code', function (): void {
    $user = adminUser();
    $this->actingAs($user);

    $this->get(route('2fa.setup'))->assertOk();

    $secret = session('2fa.pending_secret');
    expect($secret)->toBeString();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('2fa.confirm'), ['code' => $code])
        ->assertRedirect(route('2fa.recovery-codes'));

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue();
    expect($user->two_factor_secret)->toBe($secret);
    expect($user->two_factor_recovery_codes)->toHaveCount(8);
});

it('rejects invalid TOTP code on setup confirmation', function (): void {
    $user = adminUser();
    $this->actingAs($user);
    $this->get(route('2fa.setup'));

    $this->post(route('2fa.confirm'), ['code' => '000000'])
        ->assertRedirect();

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse();
});

it('redirects confirmed admin to challenge until code entered', function (): void {
    $g2fa = app(Google2FA::class);
    $secret = $g2fa->generateSecretKey();
    $user = adminUser([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => ['r1', 'r2'],
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect(route('2fa.challenge'));

    $code = $g2fa->getCurrentOtp($secret);
    $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => $code])
        ->assertRedirect();

    expect(session('2fa.passed_at'))->toBeInt();
});

it('consumes a recovery code exactly once', function (): void {
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = adminUser([
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => 'aaaa-bbbb'])
        ->assertRedirect();

    $user->refresh();
    expect($user->two_factor_recovery_codes)->toBe(['cccc-dddd']);

    session()->forget('2fa.passed_at');

    $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => 'aaaa-bbbb'])
        ->assertRedirect()
        ->assertSessionHasErrors('code');
});

it('does not gate non-admin users', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('teacher');

    // Non-admin passes through the middleware; they are blocked later by canAccessPanel (403).
    $this->actingAs($user)->get('/admin')->assertForbidden();
});
