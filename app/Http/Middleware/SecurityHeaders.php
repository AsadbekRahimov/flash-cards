<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=15552000; includeSubDomains',
            );
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (str_contains($contentType, 'text/html') && ! $response->headers->has('Content-Security-Policy')) {
            // Error pages (404, 403, 500, ...) run no JavaScript: they get the strict policy, not the panels' one.
            // Debug mode keeps the full policy: the framework's exception renderer needs its inline scripts.
            $scriptSrc = $response->getStatusCode() >= 400 && ! config('app.debug')
                ? "'none'"
                : "'self' 'unsafe-inline' 'unsafe-eval'";
            $response->headers->set('Content-Security-Policy', self::contentSecurityPolicy($scriptSrc));
        }

        return $response;
    }

    /**
     * The app-wide policy; `$scriptSrc` is the only directive a route group may replace
     * (static pages pass `'none'`, see {@see StaticPageCsp}).
     */
    public static function contentSecurityPolicy(string $scriptSrc = "'self' 'unsafe-inline' 'unsafe-eval'"): string
    {
        return "default-src 'self'; "
            ."script-src {$scriptSrc}; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: blob:; "
            ."font-src 'self' data:; "
            ."connect-src 'self'; "
            ."object-src 'none'; "
            ."base-uri 'self'; "
            // Forms post to the same origin only (2FA, Filament logout). Billing redirects to Payme / Click
            // are Livewire JS navigations, not form submissions, so no external host is needed.
            ."form-action 'self'; "
            ."frame-ancestors 'self';";
    }
}
