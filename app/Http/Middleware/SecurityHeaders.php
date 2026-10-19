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
            $response->headers->set('Content-Security-Policy', self::contentSecurityPolicy());
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
            ."frame-ancestors 'self';";
    }
}
