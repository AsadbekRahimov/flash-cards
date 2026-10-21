<?php

declare(strict_types=1);

/*
 * `.env.example` is the operator's checklist: every variable that the project's own config files
 * read through env() must be listed there (once), and the Redis queue's retry_after must stay
 * above the production worker's --timeout. Config files of vendor packages (sentry, filament,
 * permission, ...) and driver variables we do not use (memcached, SQS, ...) are out of scope.
 */

const ENV_OWN_CONFIGS = ['app', 'backup', 'billing', 'cache', 'content', 'leagues', 'learners', 'learning', 'localization', 'logging', 'operations', 'queue', 'referrals', 'session', 'site', 'telegram', 'twa'];

/** @return list<string> */
function envExampleKeys(): array
{
    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $m);

    return $m[1];
}

it('lists every env() variable of the project config files in .env.example, once', function (): void {
    $listed = envExampleKeys();
    $used = [];
    foreach (ENV_OWN_CONFIGS as $file) {
        preg_match_all("/env\(\s*'([A-Z][A-Z0-9_]*)'/", (string) file_get_contents(config_path($file.'.php')), $m);
        $used = [...$used, ...$m[1]];
    }

    // Variables of drivers the project does not run (documented in the framework's own config).
    $driverOnly = '/^(LOG_(SLACK|PAPERTRAIL|SYSLOG|STDERR)|PAPERTRAIL_|SLACK_|AWS_|DB_(CACHE|QUEUE)|BEANSTALKD_|SQS_|DYNAMODB_|MEMCACHED_|REDIS_(CLUSTER|PERSISTENT|PREFIX|URL)|SESSION_TABLE|MAIL_(SCHEME|URL|EHLO|LOG_CHANNEL|SENDMAIL)|POSTMARK_|RESEND_)/';
    $missing = array_values(array_filter(
        array_unique($used),
        fn (string $key): bool => ! in_array($key, $listed, true) && preg_match($driverOnly, $key) !== 1,
    ));

    expect($missing)->toBe([])
        ->and(array_keys(array_filter(array_count_values($listed), fn (int $n): bool => $n > 1)))->toBe([]);
});

it('keeps the Redis queue retry_after above the production worker timeout, in config and in .env.example', function (): void {
    preg_match('/--timeout=(\d+)/', (string) file_get_contents(base_path('docker-compose.prod.yml')), $m);
    $timeout = (int) $m[1];

    preg_match('/^REDIS_QUEUE_RETRY_AFTER=(\d+)/m', (string) file_get_contents(base_path('.env.example')), $example);

    expect($timeout)->toBeGreaterThan(0)
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($timeout)
        ->and((int) $example[1])->toBeGreaterThan($timeout);
});
