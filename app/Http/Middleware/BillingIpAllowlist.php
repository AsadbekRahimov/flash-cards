<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional caller allowlist of a payment provider's webhook (docs/13 §4, §10):
 * `BillingIpAllowlist:payme` reads `billing.providers.payme.ip_allowlist` — comma-separated IPs
 * / CIDRs from the provider's cabinet (`PAYME_IP_ALLOWLIST`, `CLICK_IP_ALLOWLIST`). Empty = off.
 * A caller outside the list gets a plain 403 before any authentication or parsing.
 */
final class BillingIpAllowlist
{
    public function handle(Request $request, Closure $next, string $provider): Response
    {
        $cidrs = self::cidrs($provider);
        if ($cidrs === []) {
            return $next($request);
        }

        if (! IpUtils::checkIp((string) $request->ip(), $cidrs)) {
            abort(403);
        }

        return $next($request);
    }

    /** @return list<string> */
    public static function cidrs(string $provider): array
    {
        $raw = config("billing.providers.{$provider}.ip_allowlist", '');
        $raw = is_string($raw) ? $raw : '';

        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $cidr): bool => $cidr !== ''));
    }
}
