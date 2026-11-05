<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Policies\Org\OrgOrganizationPolicy;
use App\Policies\Org\OrgPolicyResolver;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\FilamentDefaultsProvider;
use App\Providers\Filament\OrgPanelProvider;
use App\Support\Surface;
use App\Support\SurfacePackageManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Support\Facades\Process;

/**
 * Runs $callback with APP_SURFACE set to $surface in $_SERVER (the first place Surface reads; null = unset) and puts the
 * previous value back, whatever happens inside.
 */
function withAppSurface(?string $surface, Closure $callback): mixed
{
    $had = array_key_exists(Surface::ENV, $_SERVER);
    $previous = $_SERVER[Surface::ENV] ?? null;

    if ($surface === null) {
        unset($_SERVER[Surface::ENV]);
    } else {
        $_SERVER[Surface::ENV] = $surface;
    }

    try {
        return $callback();
    } finally {
        if ($had) {
            $_SERVER[Surface::ENV] = $previous;
        } else {
            unset($_SERVER[Surface::ENV]);
        }
    }
}

/**
 * `php artisan <command> --json` in a child process on the given surface (the environment of this process is inherited;
 * CI copies .env from .env.example and that is fine: nothing here touches the database).
 *
 * @param  list<string>  $command
 * @return array<mixed>
 */
function artisanJsonOnSurface(string $surface, array $command): array
{
    $result = Process::path(base_path())
        ->timeout(180)
        ->env([Surface::ENV => $surface])
        ->run([PHP_BINARY, 'artisan', ...$command, '--json', '--no-ansi']);

    expect($result->successful())->toBeTrue('artisan '.implode(' ', $command)." failed on surface {$surface}: ".$result->errorOutput().$result->output());

    $decoded = json_decode(trim($result->output()), true);
    expect($decoded)->toBeArray('artisan '.implode(' ', $command)." on surface {$surface} did not print JSON: ".$result->output());

    /** @var array<mixed> $decoded */
    return $decoded;
}

/**
 * @param  array<mixed>  $routes
 * @return list<string>
 */
function routeUris(array $routes): array
{
    return array_values(array_map(static fn (array $route): string => (string) $route['uri'], $routes));
}

/**
 * @param  array<mixed>  $routes
 * @return list<string>
 */
function routeNames(array $routes): array
{
    return array_values(array_filter(array_map(static fn (array $route): ?string => $route['name'] ?? null, $routes)));
}

describe('Surface', function (): void {
    it('is full when APP_SURFACE is unset, empty or full, and api when api (trimmed, any case)', function (): void {
        expect(withAppSurface(null, fn (): string => Surface::current()))->toBe(Surface::FULL)
            ->and(withAppSurface('', fn (): string => Surface::current()))->toBe(Surface::FULL)
            ->and(withAppSurface('full', fn (): string => Surface::current()))->toBe(Surface::FULL)
            ->and(withAppSurface('api', fn (): string => Surface::current()))->toBe(Surface::API)
            ->and(withAppSurface(' API ', fn (): bool => Surface::isApi()))->toBeTrue()
            ->and(withAppSurface('full', fn (): bool => Surface::isApi()))->toBeFalse();
    });

    it('refuses an unknown surface so a typo fails the container at its first artisan call', function (): void {
        withAppSurface('admin', fn (): string => Surface::current());
    })->throws(InvalidArgumentException::class, 'APP_SURFACE must be "full" or "api", got "admin".');

    it('excludes the admin UI and console-only packages on api, and keeps the rest', function (): void {
        expect(Surface::excludesPackageOnApi('filament/filament'))->toBeTrue()
            ->and(Surface::excludesPackageOnApi('filament/tables'))->toBeTrue()
            ->and(Surface::excludesPackageOnApi('livewire/livewire'))->toBeTrue()
            ->and(Surface::excludesPackageOnApi('blade-ui-kit/blade-icons'))->toBeTrue()
            ->and(Surface::excludesPackageOnApi('spatie/laravel-backup'))->toBeTrue()
            ->and(Surface::excludesPackageOnApi('sentry/sentry-laravel'))->toBeFalse()
            ->and(Surface::excludesPackageOnApi('irazasyed/telegram-bot-sdk'))->toBeFalse()
            ->and(Surface::excludesPackageOnApi('spatie/laravel-permission'))->toBeFalse()
            ->and(Surface::excludesPackageOnApi('nesbot/carbon'))->toBeFalse()
            ->and(Surface::excludesPackageOnApi('nunomaduro/termwind'))->toBeFalse()
            ->and(Surface::excludesPackageOnApi('not-filament/filament'))->toBeFalse();
    });
});

describe('SurfacePackageManifest', function (): void {
    $synthetic = [
        'filament/filament' => [
            'providers' => ['Filament\\FilamentServiceProvider'],
            'aliases' => ['Filament' => 'Filament\\Facades\\Filament'],
        ],
        'filament/tables' => [
            'providers' => ['Filament\\Tables\\TablesServiceProvider'],
        ],
        'livewire/livewire' => [
            'providers' => ['Livewire\\LivewireServiceProvider'],
            'aliases' => ['Livewire' => 'Livewire\\Livewire'],
        ],
        'sentry/sentry-laravel' => [
            'providers' => ['Sentry\\Laravel\\ServiceProvider', 'Sentry\\Laravel\\Tracing\\ServiceProvider'],
            'aliases' => ['Sentry' => 'Sentry\\Laravel\\Facade'],
        ],
        'acme/unknown' => [
            'providers' => ['Acme\\ServiceProvider'],
        ],
    ];

    $manifestOf = function () use ($synthetic): SurfacePackageManifest {
        // A path that does not exist: the stock class would try to build it; the preset array makes it never look.
        $manifest = new SurfacePackageManifest(new Filesystem, base_path(), base_path('bootstrap/cache/no-such-packages.php'));
        $manifest->manifest = $synthetic;

        return $manifest;
    };

    it('drops the providers and aliases of the excluded packages on the api surface', function () use ($manifestOf): void {
        $manifest = $manifestOf();

        expect(withAppSurface('api', fn (): array => $manifest->providers()))->toBe([
            'Sentry\\Laravel\\ServiceProvider',
            'Sentry\\Laravel\\Tracing\\ServiceProvider',
            'Acme\\ServiceProvider',
        ])->and(withAppSurface('api', fn (): array => $manifest->aliases()))->toBe([
            'Sentry' => 'Sentry\\Laravel\\Facade',
        ]);
    });

    it('answers as the stock manifest on the full surface and when APP_SURFACE is unset', function () use ($manifestOf): void {
        $manifest = $manifestOf();
        $allProviders = [
            'Filament\\FilamentServiceProvider',
            'Filament\\Tables\\TablesServiceProvider',
            'Livewire\\LivewireServiceProvider',
            'Sentry\\Laravel\\ServiceProvider',
            'Sentry\\Laravel\\Tracing\\ServiceProvider',
            'Acme\\ServiceProvider',
        ];
        $allAliases = [
            'Filament' => 'Filament\\Facades\\Filament',
            'Livewire' => 'Livewire\\Livewire',
            'Sentry' => 'Sentry\\Laravel\\Facade',
        ];

        expect(withAppSurface('full', fn (): array => $manifest->providers()))->toBe($allProviders)
            ->and(withAppSurface(null, fn (): array => $manifest->providers()))->toBe($allProviders)
            ->and(withAppSurface('full', fn (): array => $manifest->aliases()))->toBe($allAliases)
            ->and(withAppSurface(null, fn (): array => $manifest->aliases()))->toBe($allAliases);
    });

    it('is what the application binds, and on full it lists exactly what the stock manifest lists', function (): void {
        $bound = app(PackageManifest::class);
        $stock = new PackageManifest(new Filesystem, base_path(), app()->getCachedPackagesPath());

        expect($bound)->toBeInstanceOf(SurfacePackageManifest::class)
            ->and($bound->providers())->toBe($stock->providers())
            ->and($bound->aliases())->toBe($stock->aliases())
            ->and($bound->providers())->toContain('Filament\\FilamentServiceProvider')
            ->and($bound->providers())->toContain('Livewire\\LivewireServiceProvider');
    });

    it('reads the real manifest without Filament or Livewire on the api surface', function (): void {
        $manifest = new SurfacePackageManifest(new Filesystem, base_path(), app()->getCachedPackagesPath());
        $providers = withAppSurface('api', fn (): array => $manifest->providers());

        expect($providers)->toContain('Sentry\\Laravel\\ServiceProvider')
            ->and(array_filter($providers, static fn (string $provider): bool => str_starts_with($provider, 'Filament\\') || str_starts_with($provider, 'Livewire\\') || str_starts_with($provider, 'BladeUI\\')))->toBe([]);
    });
});

describe('bootstrap/providers.php', function (): void {
    $load = static fn (): array => require base_path('bootstrap/providers.php');

    it('registers only AppServiceProvider on the api surface', function () use ($load): void {
        expect(withAppSurface('api', $load))->toBe([AppServiceProvider::class]);
    });

    it('registers the panels on the full surface and when APP_SURFACE is unset', function () use ($load): void {
        $full = [AppServiceProvider::class, FilamentDefaultsProvider::class, AdminPanelProvider::class, OrgPanelProvider::class];

        expect(withAppSurface('full', $load))->toBe($full)
            ->and(withAppSurface(null, $load))->toBe($full);
    });
});

describe('OrgPolicyResolver on the api surface', function (): void {
    it('is never in the org panel and never touches Filament', function (): void {
        expect(withAppSurface('api', fn (): bool => OrgPolicyResolver::inOrgPanel()))->toBeFalse()
            ->and(withAppSurface('api', fn (): array => OrgPolicyResolver::candidates(Organization::class)))
            ->toBe(['App\\Policies\\OrganizationPolicy'])
            ->and(OrgPolicyResolver::MAP[Organization::class])->toBe(OrgOrganizationPolicy::class);
    });
});

describe('a process on the api surface', function (): void {
    it('serves the TWA API, /up and /health/ready and nothing of the panels, the site or the webhooks', function (): void {
        $routes = artisanJsonOnSurface('api', ['route:list']);
        $uris = routeUris($routes);
        $names = routeNames($routes);

        expect($uris)->toContain('api/twa/me')
            ->and($uris)->toContain('api/twa/auth')
            ->and($uris)->toContain('health/ready')
            ->and($uris)->toContain('up')
            ->and($names)->toContain('twa.me')
            ->and($names)->toContain('health.ready')
            ->and($uris)->not->toContain('/')
            ->and($uris)->not->toContain('admin')
            ->and($uris)->not->toContain('admin/login')
            ->and($uris)->not->toContain('telegram/webhook/{secret}')
            ->and($uris)->not->toContain('billing/webhook/payme')
            ->and($uris)->not->toContain('2fa/challenge')
            ->and(array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'filament.'))))->toBe([])
            ->and(array_values(array_filter($uris, static fn (string $uri): bool => str_starts_with($uri, 'livewire') || str_starts_with($uri, 'admin') || str_starts_with($uri, 'org'))))->toBe([]);
    });

    it('registers neither Filament nor Livewire (artisan about)', function (): void {
        $sections = static fn (array $about): array => array_map(strtolower(...), array_map(strval(...), array_keys($about)));

        $api = $sections(artisanJsonOnSurface('api', ['about']));
        $full = $sections(artisanJsonOnSurface('full', ['about']));

        expect($api)->toContain('environment')
            ->and($api)->not->toContain('filament')
            ->and($api)->not->toContain('livewire')
            // The full surface is the one the panels report themselves on (either package adds a section of its own).
            ->and(array_values(array_intersect(['filament', 'livewire'], $full)))->not->toBe([]);
    });
});

describe('a process on the full surface', function (): void {
    it('keeps the panels, the public site, the webhooks and the API as before', function (): void {
        $routes = artisanJsonOnSurface('full', ['route:list']);
        $uris = routeUris($routes);
        $names = routeNames($routes);

        expect($uris)->toContain('api/twa/me')
            ->and($uris)->toContain('health/ready')
            ->and($uris)->toContain('up')
            ->and($uris)->toContain('/')
            ->and($uris)->toContain('admin/login')
            ->and($uris)->toContain('telegram/webhook/{secret}')
            ->and($uris)->toContain('billing/webhook/payme')
            ->and($uris)->toContain('2fa/challenge')
            ->and(array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'filament.admin.'))))->not->toBe([])
            ->and(array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'filament.org.'))))->not->toBe([]);
    });
});
