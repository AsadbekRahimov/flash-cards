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
