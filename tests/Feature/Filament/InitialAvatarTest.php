<?php

declare(strict_types=1);

use App\Domain\Organizations\Enums\OrganizationRole;
use App\Filament\Support\InitialAvatarProvider;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;

/*
 * The panel avatar is the first letter of the name on a dark square, drawn locally as a data: SVG: ui-avatars.com is
 * blocked by the CSP (img-src) and would leak staff names to a third party.
 */

uses(RefreshDatabase::class);

/** Decoded SVG of the avatar for a user with this name. */
function avatarSvg(string $name): string
{
    $url = (new InitialAvatarProvider)->get(new User(['name' => $name]));

    expect($url)->toStartWith('data:image/svg+xml;base64,');

    return base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);
}

function avatarLetter(string $name): string
{
    $xml = simplexml_load_string(avatarSvg($name));

    expect($xml)->not->toBeFalse();

    return (string) $xml->text;
}

it('draws the first letter of the name, upper-cased', function (string $name, string $letter): void {
    expect(avatarLetter($name))->toBe($letter);
})->with([
    ['Admin', 'A'],
    ['ашот', 'А'],
    ['  «Ольга»', 'О'],
    ['[SYSTEM] Admin', 'S'],
    ['oʻtkir', 'O'],
    ['7 Seven', '7'],
    ['', '?'],
    ['  «»  ', '?'],
]);

it('fills the whole square with a dark background and centres a white letter', function (): void {
    $svg = avatarSvg('Admin');

    expect($svg)->toContain('viewBox="0 0 64 64"')
        ->toContain('<rect width="64" height="64" fill="#09090b"/>')
        ->toContain('text-anchor="middle"')
        ->toContain('dominant-baseline="central"')
        ->toContain('fill="#ffffff"')
        ->not->toContain('http://www.w3.org/1999/xlink')
        ->not->toContain('href=');
});

it('does not let a name inject markup', function (): void {
    $svg = avatarSvg('<script>alert(1)</script>');

    expect($svg)->not->toContain('<script');

    expect(avatarLetter('<script>'))->not->toBe('');

    // the letter is escaped even when it is a markup character that slipped past the letter filter
    expect(simplexml_load_string(avatarSvg('&amp; <b>')))->not->toBeFalse();
});

it('is set as the avatar provider of both panels', function (string $panel): void {
    expect(Filament::getPanel($panel)->getDefaultAvatarProvider())->toBe(InitialAvatarProvider::class);
})->with(['admin', 'org']);

it('renders the /admin user menu avatar locally', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $admin = User::factory()->create([
        'name' => 'Admin',
        'is_active' => true,
        'two_factor_secret' => 'stub',
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->assignRole('admin');

    $html = $this->actingAs($admin)
        ->withSession(['2fa.web.passed_at' => now()->timestamp, '2fa.web.user_id' => $admin->id])
        ->get('/admin')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('ui-avatars.com')
        ->and($html)->toContain('data:image/svg+xml;base64,'.base64_encode(avatarSvg('Admin')));
});

it('renders the /org user menu avatar locally', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $organization = Organization::factory()->create();
    $owner = User::factory()->create([
        'name' => 'Olga',
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

    expect($html)->not->toContain('ui-avatars.com')
        ->and($html)->toContain('data:image/svg+xml;base64,'.base64_encode(avatarSvg('Olga')));
});
