<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;

use function Filament\Support\generate_href_html;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

/*
 * Both panels run in SPA mode with hover prefetch (docs/20 §1.14): menu links carry `wire:navigate.hover`, and what is
 * not a panel page (2FA pages, the printable invoice, other hosts, files) is rendered as a plain link.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** Boots the panel the way a request to it would, so the global FilamentView state matches it. */
function bootSpaPanel(string $id): void
{
    Filament::setCurrentPanel(Filament::getPanel($id));
    Filament::bootCurrentPanel();
}

it('runs both panels in SPA mode with prefetching', function (string $panel): void {
    $panel = Filament::getPanel($panel);

    expect($panel->hasSpaMode())->toBeTrue()
        ->and($panel->hasSpaPrefetching())->toBeTrue();
})->with(['admin', 'org']);

it('renders the /admin side menu with wire:navigate.hover', function (): void {
    $admin = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id])
        ->get('/admin/courses')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('href="'.url('/admin/courses').'" wire:navigate.hover')
        ->and($html)->toContain('href="'.url('/admin/words').'" wire:navigate.hover');
});

it('renders the /org side menu with wire:navigate.hover', function (): void {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => OrganizationRole::Owner->value]);

    $html = $this->actingAs($owner, 'org')
        ->withSession(['2fa.org.passed_at' => now()->timestamp, '2fa.org.user_id' => $owner->id])
        ->get('/org/'.$organization->slug.'/groups')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('href="'.url('/org/'.$organization->slug.'/groups').'" wire:navigate.hover');
});

it('renders links outside the panel without wire:navigate', function (string $panel, string $url): void {
    bootSpaPanel($panel);

    $html = generate_href_html($url)->toHtml();

    expect($html)->toBe('href="'.$url.'"')
        ->and(FilamentView::hasSpaMode($url))->toBeFalse();
})->with([
    'admin: 2FA setup' => ['admin', fn () => url('/2fa/setup?guard=web')],
    'admin: 2FA challenge' => ['admin', fn () => url('/2fa/challenge')],
    'org: 2FA challenge' => ['org', fn () => url('/2fa/challenge?guard=org')],
    'org: printable invoice' => ['org', fn () => url('/org/acme/invoice/15')],
    'another host' => ['admin', 'https://t.me/lexiflow_bot'],
    'another panel host' => ['org', 'https://other.example/admin/courses'],
]);

it('keeps panel pages on wire:navigate.hover', function (string $panel, string $path): void {
    bootSpaPanel($panel);

    expect(generate_href_html(url($path))->toHtml())->toBe('href="'.url($path).'" wire:navigate.hover');
})->with([
    ['admin', '/admin/courses'],
    ['admin', '/admin/login'],
    ['org', '/org/acme/groups'],
    ['org', '/org/acme/billing'],
]);

it('keeps target=_blank links out of SPA navigation', function (): void {
    bootSpaPanel('org');

    expect(generate_href_html(url('/org/acme/invoice/15'), shouldOpenInNewTab: true)->toHtml())
        ->toBe('href="'.url('/org/acme/invoice/15').'" target="_blank"');
});

it('sends an admin without 2FA to a page that forces a full load when it lands inside a panel document', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    // What a wire:navigate click does: fetch with the navigate header; the redirect is followed to the Blade page.
    $this->actingAs($admin)
        ->withHeader('X-Livewire-Navigate', '1')
        ->get('/admin/courses')
        ->assertRedirect(route('2fa.setup', ['guard' => 'web']));

    $page = $this->actingAs($admin)->get(route('2fa.setup', ['guard' => 'web']))->assertOk()->getContent();

    expect($page)->toContain('if (window.Livewire)')
        ->toContain('window.location.reload()')
        // a plain visit of this page loads no Livewire, so the guard stays inert
        ->and($page)->not->toContain('livewire.js')
        ->and($page)->not->toContain('/livewire');
});

it('forces a full load on the 2FA challenge page too, for both guards', function (string $guard): void {
    $user = User::factory()->create([
        'is_active' => true,
        'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('admin');
    $organization = Organization::factory()->create();
    OrganizationUser::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => OrganizationRole::Owner->value]);

    $this->actingAs($user, $guard)
        ->get(route('2fa.challenge', ['guard' => $guard]))
        ->assertOk()
        ->assertSee('if (window.Livewire)', false)
        ->assertSee('window.location.reload()', false);
})->with(['web', 'org']);
