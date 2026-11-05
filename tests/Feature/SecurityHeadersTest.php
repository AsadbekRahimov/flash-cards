<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('applies baseline security headers to html responses', function (): void {
    Route::get('/_sec_headers_html', fn () => response('<html></html>')
        ->header('Content-Type', 'text/html; charset=UTF-8'));

    $response = $this->get('/_sec_headers_html');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'self'");
});

it('skips CSP for non-html responses but keeps baseline headers', function (): void {
    Route::get('/_sec_headers_json', fn () => response()->json(['ok' => true]));

    $response = $this->get('/_sec_headers_json');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('sets HSTS only over HTTPS', function (): void {
    Route::get('/_sec_headers_plain', fn () => response('ok'));

    $plain = $this->get('/_sec_headers_plain');
    expect($plain->headers->has('Strict-Transport-Security'))->toBeFalse();

    $secure = $this->get('https://localhost/_sec_headers_plain');
    expect($secure->headers->get('Strict-Transport-Security'))
        ->toContain('max-age=15552000')
        ->toContain('includeSubDomains');
});

// --- CSP: directives without fallback, panels vs. error pages ---------------

it('adds object-src, base-uri and form-action to the app-wide policy', function (): void {
    Route::get('/_sec_headers_csp', fn () => response('<html></html>')
        ->header('Content-Type', 'text/html; charset=UTF-8'));

    expect($this->get('/_sec_headers_csp')->headers->get('Content-Security-Policy'))
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");
});

it('keeps the full panel policy on the login pages', function (string $url): void {
    $csp = (string) $this->get($url)->assertOk()->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->toContain("form-action 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'");
})->with(['/admin/login', '/org/login']);

it('gives error pages the strict policy without script', function (int $status): void {
    config(['app.debug' => false]);
    Route::get('/_sec_headers_err', fn () => abort($status));

    $response = $this->get('/_sec_headers_err')->assertStatus($status);

    expect((string) $response->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'")
        ->not->toContain("'unsafe-eval'")
        ->toContain("form-action 'self'");
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
})->with([403, 404, 419, 500]);

it('gives a real 404 of an unknown url the strict policy', function (): void {
    config(['app.debug' => false]);

    expect((string) $this->get('/sitemap-does-not-exist.xml')->assertNotFound()->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'");
});

it('gives an unhandled exception (500) the strict policy', function (): void {
    config(['app.debug' => false]);
    Route::get('/_sec_headers_boom', fn () => throw new RuntimeException('boom'));

    expect((string) $this->get('/_sec_headers_boom')->assertStatus(500)->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'none'");
});

it('does not override a policy a route already set on an error response', function (): void {
    config(['app.debug' => false]);
    Route::get('/_sec_headers_own', fn () => response('<html></html>', 403)
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->header('Content-Security-Policy', "default-src 'none'"));

    expect($this->get('/_sec_headers_own')->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});
