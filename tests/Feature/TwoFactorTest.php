<?php

declare(strict_types=1);

use App\Domain\Administration\Services\TwoFactorAttemptService;
use App\Http\Middleware\RequireOrgTwoFactor;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Limiter / TOTP-step keys live in the cache store; with a persistent store (redis) they outlive a test.
    Cache::flush();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<string, mixed>  $attrs
 */
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
        ->assertRedirect(route('2fa.setup', ['guard' => 'web']));
});

it('lets admin finish 2FA setup with a valid TOTP code', function (): void {
    $user = adminUser();
    $this->actingAs($user);

    $this->get(route('2fa.setup'))->assertOk();

    $secret = session('2fa.web.pending_secret');
    expect($secret)->toBeString();

    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->post(route('2fa.confirm'), ['code' => $code])
        ->assertRedirect(route('2fa.recovery-codes', ['guard' => 'web']));

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeTrue();
    expect($user->two_factor_secret)->toBe($secret);
    expect($user->two_factor_recovery_codes)->toHaveCount(8);

    $this->get(route('2fa.recovery-codes'))->assertOk();
    $this->get(route('2fa.recovery-codes'))->assertRedirect('/admin');
});

it('renders the recovery-code intro escaped, with markup only from the template', function (string $locale): void {
    app('translator')->addLines([
        'twofactor.recovery.intro' => 'Intro <script>alert(1)</script> :emphasis & more',
        'twofactor.recovery.intro_emphasis' => '<b>once</b>',
    ], $locale);

    $html = view('2fa.recovery-codes', ['codes' => ['aaaa-bbbb'], 'locale' => $locale])->render();

    expect($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<b>once</b>')
        ->and($html)->toContain('Intro &lt;script&gt;alert(1)&lt;/script&gt; <strong>&lt;b&gt;once&lt;/b&gt;</strong> &amp; more')
        ->and($html)->toContain('aaaa-bbbb');
})->with(['ru', 'uz']);

it('keeps the real recovery-code intro readable in ru and uz', function (string $locale, string $emphasis): void {
    $html = view('2fa.recovery-codes', ['codes' => ['aaaa-bbbb'], 'locale' => $locale])->render();

    expect($html)->toContain("<strong>{$emphasis}</strong>")
        ->and($html)->not->toContain(':emphasis');
})->with([['ru', 'один раз'], ['uz', 'faqat bir marta']]);

it('never reveals existing recovery codes before the 2FA challenge', function (): void {
    $user = adminUser([
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
        'two_factor_recovery_codes' => ['secret-recovery-code'],
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('2fa.recovery-codes'))
        ->assertRedirect(route('2fa.challenge', ['guard' => 'web']))
        ->assertDontSee('secret-recovery-code');
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
        ->assertRedirect(route('2fa.challenge', ['guard' => 'web']));

    $code = $g2fa->getCurrentOtp($secret);
    $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => $code])
        ->assertRedirect();

    expect(session('2fa.web.passed_at'))->toBeInt()
        ->and(session('2fa.web.user_id'))->toBe($user->id);
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

    session()->forget(['2fa.web.passed_at', '2fa.web.user_id']);

    $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => 'aaaa-bbbb'])
        ->assertRedirect()
        ->assertSessionHasErrors('code');
});

it('does not require 2FA from teachers', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('teacher');

    $this->actingAs($user)->get('/admin')->assertOk();
});

/**
 * @return array{0: User, 1: string}
 */
function twoFactorUser(string $role = 'admin'): array
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => $secret,
        'two_factor_recovery_codes' => ['rc-one-aaaa', 'rc-two-bbbb'],
        'two_factor_confirmed_at' => now(),
    ]);
    if ($role === 'admin') {
        $user->assignRole('admin');
    }

    return [$user, $secret];
}

it('does not let a 2FA pass of the /org user open /admin for a different web user (and vice versa)', function (): void {
    [$admin] = twoFactorUser();
    [$orgUser] = twoFactorUser('none');

    // org account passed 2FA; web admin did not.
    $this->actingAs($admin, 'web')->actingAs($orgUser, 'org')
        ->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $orgUser->id])
        ->get('/admin')
        ->assertRedirect(route('2fa.challenge', ['guard' => 'web']));

    // a mark in the web slot left by ANOTHER account does not count either
    $this->actingAs($admin, 'web')
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $orgUser->id])
        ->get('/admin')
        ->assertRedirect(route('2fa.challenge', ['guard' => 'web']));

    // the web admin's own mark opens /admin
    $this->actingAs($admin, 'web')
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id])
        ->get('/admin')
        ->assertOk();
});

it('does not take the admin pass as a pass of a different org user', function (): void {
    [$admin] = twoFactorUser();
    [$orgUser] = twoFactorUser('none');

    $this->actingAs($admin, 'web')->actingAs($orgUser, 'org');

    $request = Request::create('/org/x');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id]);

    $middleware = app(RequireOrgTwoFactor::class);
    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->isRedirect(route('2fa.challenge', ['guard' => 'org'])))->toBeTrue();
});

it('verifies the explicitly chosen guard even when two accounts are logged in', function (): void {
    [$admin, $adminSecret] = twoFactorUser();
    [$orgUser, $orgSecret] = twoFactorUser('none');
    $g2fa = app(Google2FA::class);

    $this->actingAs($admin, 'web')->actingAs($orgUser, 'org');

    // Without a guard the request is ambiguous.
    $this->post(route('2fa.verify'), ['code' => $g2fa->getCurrentOtp($orgSecret)])->assertStatus(422);
    $this->post(route('2fa.verify', ['guard' => 'nope']), ['code' => '123456'])->assertNotFound();

    // The org user's secret is checked for the org guard only.
    $this->post(route('2fa.verify', ['guard' => 'web']), ['code' => $g2fa->getCurrentOtp($orgSecret)])
        ->assertSessionHasErrors('code');
    expect(session('2fa.web.passed_at'))->toBeNull()->and(session('2fa.org.passed_at'))->toBeNull();

    $this->post(route('2fa.verify', ['guard' => 'org']), ['code' => $g2fa->getCurrentOtp($orgSecret)])->assertRedirect('/org');
    expect(session('2fa.org.user_id'))->toBe($orgUser->id)->and(session('2fa.web.passed_at'))->toBeNull();

    $this->post(route('2fa.verify', ['guard' => 'web']), ['code' => $g2fa->getCurrentOtp($adminSecret)])->assertRedirect('/admin');
    expect(session('2fa.web.user_id'))->toBe($admin->id);
});

it('sends a guest of the chosen guard to that panel login', function (): void {
    [$admin] = twoFactorUser();

    $this->actingAs($admin, 'web')
        ->get(route('2fa.challenge', ['guard' => 'org']))
        ->assertRedirect(Filament::getPanel('org')->getLoginUrl());
});

it('does not use a pending secret generated for another user', function (): void {
    $user = adminUser();
    $foreign = app(Google2FA::class)->generateSecretKey();

    $this->actingAs($user)
        ->withSession(['2fa.web.pending_secret' => $foreign, '2fa.web.pending_user_id' => $user->id + 100])
        ->get(route('2fa.setup'))
        ->assertOk();

    expect(session('2fa.web.pending_secret'))->not->toBe($foreign)
        ->and(session('2fa.web.pending_user_id'))->toBe($user->id);

    $this->post(route('2fa.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($foreign)])
        ->assertSessionHasErrors('code');
    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('does not show recovery codes unlocked for another user', function (): void {
    [$user] = twoFactorUser();

    $this->actingAs($user)
        ->withSession(['2fa.web.show_recovery_codes' => $user->id + 1, '2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $user->id])
        ->get(route('2fa.recovery-codes'))
        ->assertRedirect('/admin');
});

it('answers 429 on the 6th wrong code within a minute, per account', function (): void {
    RateLimiter::clear('2fa:ip:127.0.0.1');
    [$a] = twoFactorUser();
    [$b, $bSecret] = twoFactorUser();

    $this->actingAs($a);
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('2fa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    }
    $this->post(route('2fa.verify'), ['code' => '000000'])->assertStatus(429)->assertHeader('Retry-After');

    // another account is independent
    $this->actingAs($b)
        ->post(route('2fa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($bSecret)])
        ->assertRedirect('/admin');

    // after the limiter is cleared (window elapsed) the account works again
    RateLimiter::clear('2fa:acct:'.$a->id);
    $this->actingAs($a)->post(route('2fa.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
});

it('throttles setup confirmation too', function (): void {
    $user = adminUser();
    $this->actingAs($user)->get(route('2fa.setup'));

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('2fa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    }
    $this->post(route('2fa.confirm'), ['code' => '000000'])->assertStatus(429);
});

it('throttles by IP across accounts', function (): void {
    $this->actingAs(adminUser());
    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit('2fa:ip:127.0.0.1', 60);
    }

    [$other] = twoFactorUser();
    $this->actingAs($other)->post(route('2fa.verify'), ['code' => '000000'])->assertStatus(429);
});

it('clears the account counter after a successful check', function (): void {
    [$user, $secret] = twoFactorUser();
    $this->actingAs($user);

    for ($i = 0; $i < 3; $i++) {
        $this->post(route('2fa.verify'), ['code' => '000000']);
    }
    $this->post(route('2fa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/admin');

    expect(RateLimiter::attempts('2fa:acct:'.$user->id))->toBe(0);
});

it('rejects a replay of an already used TOTP code', function (): void {
    [$user, $secret] = twoFactorUser();
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($user)->post(route('2fa.verify'), ['code' => $code])->assertRedirect('/admin');
    expect(session('2fa.web.passed_at'))->toBeInt();

    session()->forget(['2fa.web.passed_at', '2fa.web.user_id']);

    $this->post(route('2fa.verify'), ['code' => $code])->assertSessionHasErrors('code');
    expect(session('2fa.web.passed_at'))->toBeNull();
});

it('locks the account for the rest of the hour after 20 failures, even when the minute window is clear', function (): void {
    [$user, $secret] = twoFactorUser();
    for ($i = 0; $i < 20; $i++) {
        RateLimiter::hit('2fa:acct-hour:'.$user->id, 3600);
    }
    RateLimiter::clear('2fa:acct:'.$user->id);

    $response = $this->actingAs($user)
        ->post(route('2fa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertStatus(429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(60);
});

it('does not reset the hourly counter on success and counts failures in it', function (): void {
    [$user, $secret] = twoFactorUser();
    $this->actingAs($user);

    $this->post(route('2fa.verify'), ['code' => '000000']);
    $this->post(route('2fa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/admin');

    expect((int) RateLimiter::attempts('2fa:acct-hour:'.$user->id))->toBe(1);
});

it('counts failures per account, not per guard', function (): void {
    [$user] = twoFactorUser();
    $this->actingAs($user)->post(route('2fa.verify'), ['code' => '000000']);

    expect((int) RateLimiter::attempts('2fa:acct:'.$user->id))->toBe(1);
});

it('claims a TOTP step atomically: a step already taken by another request is a replay', function (): void {
    [$user, $secret] = twoFactorUser();
    $g2fa = app(Google2FA::class);
    $code = $g2fa->getCurrentOtp($secret);
    $step = $g2fa->verifyKeyNewer($secret, $code, 0);

    // A concurrent request claimed the step, but has not yet stored last_step.
    Cache::add("2fa:step:{$user->id}:{$step}", 1, 180);

    expect(app(TwoFactorAttemptService::class)->verifyTotp('web', $user, $secret, $code))->toBeFalse();
});

it('keeps the 2FA mark after the session id is regenerated on verify', function (): void {
    [$user, $secret] = twoFactorUser();
    $this->actingAs($user)->withSession(['x' => 1]);
    $old = session()->getId();

    $this->post(route('2fa.verify'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect('/admin');

    expect(session()->getId())->not->toBe($old)
        ->and(session('2fa.web.passed_at'))->toBeInt()
        ->and(session('x'))->toBe(1);
});

it('logs failed attempts with user, guard and ip', function (): void {
    Log::spy();
    [$user] = twoFactorUser();

    $this->actingAs($user)->post(route('2fa.verify'), ['code' => '000000']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $ctx): bool => $m === '2FA attempt failed'
        && $ctx['user_id'] === $user->id && $ctx['guard'] === 'web' && isset($ctx['ip']))->once();
});
