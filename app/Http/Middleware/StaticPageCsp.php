<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public static pages (landing, offer, privacy) run no JavaScript: their CSP is the app-wide one
 * with `script-src 'none'`. Route middleware runs inside the global {@see SecurityHeaders}, which
 * leaves an already set `Content-Security-Policy` untouched.
 */
final class StaticPageCsp
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', SecurityHeaders::contentSecurityPolicy("'none'"));

        return $response;
    }
}
