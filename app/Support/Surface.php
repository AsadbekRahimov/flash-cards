<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Which face of the application this process serves (docs/02 §2.1, docs/DEVOPS_GUIDE.md «Две поверхности приложения»).
 *
 * `full` (the default): everything - the panels `/admin` and `/org`, the public site, the Telegram and payment webhooks,
 * the 2FA pages, the queue workers, the scheduler and the console. `api`: ONLY `/api/*` (the TWA Mini App), `/up` and
 * `/health/ready`. On `api` the process registers neither the panel providers of bootstrap/providers.php nor the
 * packages that exist for the panels or the console alone (API_EXCLUDED_PACKAGES: Filament, Livewire, the Blade icon
 * sets, ...), and loads no web routes: a learner's request pays for none of the admin code, and the process that takes
 * learner traffic carries none of it. Behaviour of the API itself is identical on both surfaces.
 *
 * The surface is read from the PROCESS ENVIRONMENT (APP_SURFACE), never from the config: it decides which providers are
 * registered and which routes are loaded, i.e. it is needed before the configuration exists, and a cached config would
 * freeze it. Under compose it is `environment:` of the service (php-fpm hands its environment to the workers: the
 * official image sets `clear_env = no`); the Dockerfile sets the default `full`, so the queue workers and the scheduler
 * of the same image are full. A `.env` line works too, because every question is asked after Dotenv has run:
 * bootstrap/providers.php is required by RegisterProviders, the route files by the booting RouteServiceProvider, the
 * package manifest when the providers are registered. Octane keeps one process per worker: the answer is the same for
 * every request it serves, so the api container moves to Octane by changing its command, not this class.
 *
 * Not memoised on purpose: three array reads per boot cost nothing, and a test can switch the surface by setting
 * $_SERVER[Surface::ENV] without a reset hook.
 */
final class Surface
{
    public const ENV = 'APP_SURFACE';

    public const FULL = 'full';

    public const API = 'api';

    /**
     * Packages (keys of bootstrap/cache/packages.php, i.e. composer names) whose discovered providers and aliases the `api`
     * surface skips. Admin UI: Livewire, the Blade icon sets, the capture directive and the power joins Filament's tables
     * use (the application itself calls no joinRelationship()); every `filament/*` package is matched by
     * API_EXCLUDED_PREFIXES. Console or panels only: tinker, the backup with its signal-aware commands, the Laravel wrapper
     * of Google2FA (the 2FA pages; the service uses the bare library, which needs no provider) and the auditing package,
     * which nothing in the application uses.
     *
     * Kept on `api` on purpose: sentry (both providers), the Telegram SDK (the Stars invoices of /api/twa/billing go through
     * its Api class), carbon (the locale of dates in API responses follows the app locale), termwind (the artisan output of
     * the caches built at container start) and spatie/laravel-permission (User::HasRoles is reachable from the organization
     * membership code, and the provider is cheap).
     *
     * @var list<string>
     */
    public const API_EXCLUDED_PACKAGES = [
        'blade-ui-kit/blade-heroicons',
        'blade-ui-kit/blade-icons',
        'kirschbaum-development/eloquent-power-joins',
        'laravel/tinker',
        'livewire/livewire',
        'owen-it/laravel-auditing',
        'pragmarx/google2fa-laravel',
        'ryangjchandler/blade-capture-directive',
        'spatie/laravel-backup',
        'spatie/laravel-signal-aware-command',
    ];

    /** @var list<string> */
    public const API_EXCLUDED_PREFIXES = [
        'filament/',
    ];

    /**
     * The current surface: `full` or `api`. An empty or unset APP_SURFACE is `full`; the value is trimmed and
     * case-insensitive. Anything else throws, so a typo fails the container at its first artisan call
     * (`config:cache` in the entrypoint) instead of silently serving the full application as the api container.
     */
    public static function current(): string
    {
        $raw = $_SERVER[self::ENV] ?? $_ENV[self::ENV] ?? getenv(self::ENV);
        $surface = is_string($raw) ? strtolower(trim($raw)) : '';

        return match ($surface) {
            '', self::FULL => self::FULL,
            self::API => self::API,
            default => throw new InvalidArgumentException(sprintf(
                '%s must be "%s" or "%s", got "%s".',
                self::ENV,
                self::FULL,
                self::API,
                $surface,
            )),
        };
    }

    public static function isApi(): bool
    {
        return self::current() === self::API;
    }

    /** Whether the discovered providers and aliases of this composer package are skipped on the `api` surface. */
    public static function excludesPackageOnApi(string $package): bool
    {
        if (in_array($package, self::API_EXCLUDED_PACKAGES, true)) {
            return true;
        }

        foreach (self::API_EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($package, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
