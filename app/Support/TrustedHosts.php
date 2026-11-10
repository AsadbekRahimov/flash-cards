<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Host names the application answers to (`Middleware::trustHosts()` in bootstrap/app.php): the host of APP_URL, the
 * extra ones of APP_TRUSTED_HOSTS and the loopback names - the container healthcheck (`curl http://127.0.0.1:8000/up`)
 * has no other. Anything else is a 400 in production, so a forged Host cannot poison the links the application builds
 * (the password reset mail of /org).
 */
final class TrustedHosts
{
    /**
     * The host PATTERNS of `Request::setTrustedHosts()` (regular expressions, matched unanchored): every name is quoted and anchored.
     *
     * @return list<string>
     */
    public static function list(): array
    {
        $hosts = ['localhost', '127.0.0.1'];

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }

        /** @var list<string> $extra */
        $extra = (array) config('app.trusted_hosts', []);
        foreach ($extra as $host) {
            $hosts[] = $host;
        }

        return array_values(array_unique(array_map(
            static fn (string $host): string => '^'.preg_quote(strtolower($host)).'$',
            $hosts,
        )));
    }

    /**
     * Parses APP_TRUSTED_HOSTS: comma-separated host names; blanks and empty entries are dropped.
     *
     * @return list<string>
     */
    public static function fromEnv(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $h): bool => $h !== ''));
    }
}
