<?php

declare(strict_types=1);

use App\Support\TrustedProxies;

/*
 * Read by Illuminate\Http\Middleware\TrustProxies (global middleware) on every request when bootstrap/app.php names no
 * proxies - Laravel 13 `TrustProxies::setTrustedProxyIpAddresses()`: `$this->proxies() ?: config('trustedproxy.proxies')`.
 * The header mask is NOT read from here (the middleware takes it from `Middleware::trustProxies(headers:)` in
 * bootstrap/app.php, see App\Support\TrustedProxies::HEADERS). docs/07 «Доверенные прокси», docs/21 §3.3.
 */
return [

    /*
    | Addresses / CIDR networks whose X-Forwarded-* headers are believed. Empty (the default, php-fpm behind nginx over
    | FastCGI: REMOTE_ADDR is the client) = nobody. Octane mode: the fixed subnet of the compose network (COMPOSE_SUBNET),
    | i.e. the nginx container. A catch-all throws here, which fails `config:cache` at container start on purpose.
    */
    'proxies' => TrustedProxies::fromEnv(env('TRUSTED_PROXIES')),

];
