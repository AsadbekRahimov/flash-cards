<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class TelegramIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('telegram.ip_allowlist.enabled', false)) {
            return $next($request);
        }

        /** @var list<string> $cidrs */
        $cidrs = (array) config('telegram.ip_allowlist.cidrs', []);
        $ip = (string) $request->ip();

        if (! IpUtils::checkIp($ip, $cidrs)) {
            abort(403);
        }

        return $next($request);
    }
}
