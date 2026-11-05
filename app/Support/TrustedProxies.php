<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The proxies whose X-Forwarded-* headers the application believes (config/trustedproxy.php, docs/07 «Доверенные
 * прокси», docs/21 §3.3).
 *
 * Behind nginx as an HTTP proxy (the Octane mode of app-api) REMOTE_ADDR is the address of the nginx container and the
 * learner's address travels in X-Forwarded-For: the per-IP limiters (`twa-auth`, the billing webhooks), the webhook
 * allowlists and `isSecure()` all depend on that header being read - and on it being read from nginx ONLY. A catch-all
 * (`*`, `**`, the framework's `REMOTE_ADDR` keyword, a /0 network) would make every caller a trusted proxy: under
 * php-fpm REMOTE_ADDR is the real client, and such a client could then pick its own address for the limiters with one
 * header. The value is therefore the fixed subnet of the compose network (COMPOSE_SUBNET), and a catch-all throws -
 * `config:cache` in the container entrypoint runs this at start-up, so the container refuses to start.
 */
final class TrustedProxies
{
    /**
     * The headers taken from a trusted proxy. Not the framework's default: that one also trusts X-Forwarded-Prefix,
     * which nginx passes through from the client untouched (it only rewrites X-Forwarded-For/-Proto/-Host/-Port), and a
     * client-chosen prefix would land in every generated URL. The framework reads this mask from bootstrap/app.php
     * (`Middleware::trustProxies(headers:)`), not from the config file.
     */
    public const HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Parses TRUSTED_PROXIES: a comma-separated list of addresses or CIDR networks; blanks around the entries and empty
     * entries are dropped, so an unset or empty variable trusts nobody (php-fpm, where REMOTE_ADDR is the client).
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException on a catch-all entry or a value that is not a string
     */
    public static function fromEnv(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('TRUSTED_PROXIES must be a comma-separated list of proxy addresses or CIDR networks.');
        }

        $proxies = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (self::isCatchAll($entry)) {
                throw new InvalidArgumentException(sprintf(
                    'TRUSTED_PROXIES entry "%s" would trust the X-Forwarded-* headers of every caller; list the proxy addresses or networks (e.g. 172.28.0.0/16).',
                    $entry,
                ));
            }

            $proxies[] = $entry;
        }

        return $proxies;
    }

    private static function isCatchAll(string $entry): bool
    {
        if (in_array($entry, ['*', '**'], true) || strcasecmp($entry, 'REMOTE_ADDR') === 0) {
            return true;
        }

        // 0.0.0.0/0 and ::/0 are catch-alls spelled as networks.
        return preg_match('~/0+$~', $entry) === 1;
    }
}
