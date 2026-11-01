<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The `web` group and the `except` list of PreventRequestForgery reach the router and the middleware class only when
    // the HTTP kernel is resolved (a test resolves it with its first request). Without it every list below is empty.
    app(HttpKernel::class);
});

/*
 * Laravel 13 renamed the CSRF middleware to PreventRequestForgery. VerifyCsrfToken and ValidateCsrfToken are now empty,
 * deprecated SUBCLASSES of it, and the router removes an excluded class and its subclasses - never its parent. So
 * `->withoutMiddleware([VerifyCsrfToken::class])` silently keeps the check of the `web` group: public pages would
 * start a session they do not have, and the Telegram / Payme / Click webhooks would answer 419 in production.
 *
 * PreventRequestForgery::handle() skips the check while the app runs as "testing", so the status of a POST in a test
 * proves nothing. These tests read the middleware list the router really builds for a route
 * (`Router::gatherRouteMiddleware()`: groups expanded, exclusions applied), and one test lifts the "testing" shortcut
 * to see the real 419 / pass-through.
 */

/**
 * The CSRF classes (PreventRequestForgery or any subclass, the deprecated aliases included) the router would run.
 *
 * @return list<string>
 */
function forgeryChecksOf(Route $route): array
{
    $checks = [];

    foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
        $class = is_string($middleware) ? explode(':', $middleware, 2)[0] : '';

        if ($class !== '' && is_a($class, PreventRequestForgery::class, true)) {
            $checks[] = $class;
        }
    }

    return $checks;
}

function namedRouteOf(string $name): Route
{
    $route = RouteFacade::getRoutes()->getByName($name);

    expect($route)->toBeInstanceOf(Route::class);

    return $route;
}

// --- routes that must stay open ------------------------------------------------------------------------------------

it('runs no CSRF check on a route that is stateless by design', function (string $name): void {
    expect(forgeryChecksOf(namedRouteOf($name)))->toBe([]);
})->with([
    // public site: static pages, no session / cookies
    'site.home',
    'site.offer',
    'site.privacy',
    'site.home.localized',
    'site.offer.localized',
    'site.privacy.localized',
    // readiness probe
    'health.ready',
    // payment providers: Payme JSON-RPC, Click prepare / complete, and the payer's return page
    'billing.webhook.payme',
    'billing.webhook.click.prepare',
    'billing.webhook.click.complete',
    'billing.return',
]);

it('really lifts the check from every route that opts out of it with withoutMiddleware()', function (): void {
    $optedOut = array_values(array_filter(
        RouteFacade::getRoutes()->getRoutes(),
        static fn (Route $route): bool => array_filter(
            $route->excludedMiddleware(),
            static fn (mixed $excluded): bool => is_string($excluded) && is_a($excluded, PreventRequestForgery::class, true),
        ) !== [],
    ));

    // The eleven routes above; fewer would mean the loop checks nothing.
    expect(count($optedOut))->toBeGreaterThanOrEqual(11);

    $stillChecked = array_values(array_map(
        static fn (Route $route): string => $route->uri(),
        array_filter($optedOut, static fn (Route $route): bool => forgeryChecksOf($route) !== []),
    ));

    expect($stillChecked)->toBe([]);
});

it('keeps the opt-out on the class that the web group really contains', function (): void {
    // `withoutMiddleware(VerifyCsrfToken::class)` removes nothing from the web group; the group holds the parent.
    $webGroup = app('router')->getMiddlewareGroups()['web'];

    expect($webGroup)->toContain(PreventRequestForgery::class)
        ->and($webGroup)->not->toContain(VerifyCsrfToken::class)
        ->and($webGroup)->not->toContain(ValidateCsrfToken::class);

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        expect($route->excludedMiddleware())
            ->not->toContain(VerifyCsrfToken::class)
            ->not->toContain(ValidateCsrfToken::class);
    }
});

it('excludes the Telegram and payment webhook paths from the check by path', function (): void {
    $excluded = app(PreventRequestForgery::class)->getExcludedPaths();

    expect($excluded)->toContain('telegram/webhook/*')
        ->and($excluded)->toContain('billing/webhook/*');
});

// --- routes that must stay protected -------------------------------------------------------------------------------

it('keeps the CSRF check on the pages of both Filament panels', function (string $name): void {
    expect(forgeryChecksOf(namedRouteOf($name)))->not->toBe([]);
})->with([
    'admin login' => 'filament.admin.auth.login',
    'admin logout' => 'filament.admin.auth.logout',
    'admin dashboard' => 'filament.admin.pages.dashboard',
    'org login' => 'filament.org.auth.login',
    'org logout' => 'filament.org.auth.logout',
    'org password reset request' => 'filament.org.auth.password-reset.request',
    'org dashboard' => 'filament.org.pages.dashboard',
]);

it('has the CSRF check in the middleware stack of the admin and the org panel', function (string $panel): void {
    $stack = array_map(
        static fn (string $middleware): string => explode(':', $middleware, 2)[0],
        array_values(array_filter(Filament::getPanel($panel)->getMiddleware(), 'is_string')),
    );

    expect(array_filter($stack, static fn (string $class): bool => is_a($class, PreventRequestForgery::class, true)))->not->toBe([]);
})->with(['admin', 'org']);

it('keeps the CSRF check on the two-factor forms and on the Livewire endpoint', function (string $name): void {
    expect(forgeryChecksOf(namedRouteOf($name)))->not->toBe([]);
})->with([
    '2fa.setup',
    '2fa.confirm',
    '2fa.challenge',
    '2fa.verify',
    '2fa.recovery-codes',
    'default-livewire.update',
]);

// --- the real thing, with the "testing" shortcut lifted ------------------------------------------------------------

it('answers 419 to a POST without a token and lets the open webhooks through once the testing shortcut is lifted', function (): void {
    // PreventRequestForgery::handle() does not check anything while app()->environment() is "testing".
    app()->detectEnvironment(static fn (): string => 'production');

    RouteFacade::middleware('web')->group(function (): void {
        RouteFacade::post('/_probe/protected', static fn (): string => 'reached');
        RouteFacade::post('/_probe/opted-out', static fn (): string => 'reached')->withoutMiddleware([PreventRequestForgery::class]);
    });

    $this->post('/_probe/protected')->assertStatus(419);
    $this->post('/_probe/opted-out')->assertOk()->assertSee('reached');

    // The webhooks are not authenticated (wrong secret / no credentials): they must answer with their own status, never 419.
    foreach ([
        '/telegram/webhook/0123456789abcdef0123' => 404,
        '/billing/webhook/payme' => null,
        '/billing/webhook/click/prepare' => null,
        '/billing/webhook/click/complete' => null,
    ] as $url => $status) {
        $response = $this->postJson($url, []);

        expect($response->getStatusCode())->not->toBe(419)->toBeLessThan(500);

        if ($status !== null) {
            $response->assertStatus($status);
        }
    }
});
