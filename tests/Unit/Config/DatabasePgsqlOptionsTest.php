<?php

declare(strict_types=1);

use Pdo\Pgsql;

/**
 * config/database.php and config/backup.php as production reads them: the pgsql driver options and the direct
 * connection come from the environment. A file is required anew for every case with the variables set the way
 * phpunit.xml / .env set them, and every source Laravel's env() reads ($_SERVER, $_ENV, getenv) is put back exactly.
 *
 * @param  array<string, string|null>  $env  null = the variable is absent
 * @return array<string, mixed>
 */
function loadConfigFileWith(string $file, array $env): array
{
    $snapshot = [];
    foreach (array_keys($env) as $key) {
        $snapshot[$key] = [
            array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            getenv($key) === false ? null : getenv($key),
        ];
    }

    $apply = function (string $key, ?string $server, ?string $envConst, ?string $putenv): void {
        if ($server === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }
        if ($envConst === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $envConst;
        }
        putenv($putenv === null ? $key : "{$key}={$putenv}");
    };

    foreach ($env as $key => $value) {
        $apply($key, $value, $value, $value);
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require base_path("config/{$file}.php");

        return $config;
    } finally {
        foreach ($snapshot as $key => [$server, $envConst, $putenv]) {
            $apply($key, $server, $envConst, $putenv);
        }
    }
}

beforeEach(function (): void {
    if (! extension_loaded('pdo_pgsql')) {
        $this->markTestSkipped('pdo_pgsql is not loaded: the pgsql driver options are empty then.');
    }
});

it('keeps pgsql connections non-persistent unless DB_PERSISTENT says so', function (): void {
    $absent = loadConfigFileWith('database', ['DB_PERSISTENT' => null]);
    $off = loadConfigFileWith('database', ['DB_PERSISTENT' => 'false']);
    $on = loadConfigFileWith('database', ['DB_PERSISTENT' => 'true']);

    expect($absent['connections']['pgsql']['options'][PDO::ATTR_PERSISTENT])->toBeFalse()
        ->and($off['connections']['pgsql']['options'][PDO::ATTR_PERSISTENT])->toBeFalse()
        ->and($on['connections']['pgsql']['options'][PDO::ATTR_PERSISTENT])->toBeTrue();
});

it('sends unnamed statements by default and lets DB_DISABLE_PREPARES switch named ones back on', function (): void {
    $absent = loadConfigFileWith('database', ['DB_DISABLE_PREPARES' => null]);
    $named = loadConfigFileWith('database', ['DB_DISABLE_PREPARES' => 'false']);

    expect($absent['connections']['pgsql']['options'][Pgsql::ATTR_DISABLE_PREPARES])->toBeTrue()
        ->and($absent['connections']['pgsql_direct']['options'][Pgsql::ATTR_DISABLE_PREPARES])->toBeTrue()
        ->and($named['connections']['pgsql']['options'][Pgsql::ATTR_DISABLE_PREPARES])->toBeFalse();
});

it('never makes the direct connection persistent, whatever DB_PERSISTENT says', function (): void {
    $config = loadConfigFileWith('database', ['DB_PERSISTENT' => 'true']);

    expect($config['connections']['pgsql_direct']['options'])->not->toHaveKey(PDO::ATTR_PERSISTENT)
        ->and($config['connections']['pgsql_direct']['driver'])->toBe('pgsql');
});

it('points the direct connection at DB_HOST unless DB_DIRECT_HOST is set', function (): void {
    $same = loadConfigFileWith('database', ['DB_HOST' => 'pgbouncer', 'DB_PORT' => '5432', 'DB_DIRECT_HOST' => null, 'DB_DIRECT_PORT' => null]);
    $direct = loadConfigFileWith('database', ['DB_HOST' => 'pgbouncer', 'DB_PORT' => '5432', 'DB_DIRECT_HOST' => 'postgres', 'DB_DIRECT_PORT' => '5433']);

    expect($same['connections']['pgsql_direct']['host'])->toBe('pgbouncer')
        ->and($same['connections']['pgsql_direct']['port'])->toBe('5432')
        ->and($direct['connections']['pgsql']['host'])->toBe('pgbouncer')
        ->and($direct['connections']['pgsql_direct']['host'])->toBe('postgres')
        ->and($direct['connections']['pgsql_direct']['port'])->toBe('5433');

    // The same database and credentials: the direct connection is the pgsql one past the pooler, nothing else.
    foreach (['database', 'username', 'password', 'charset', 'search_path', 'sslmode'] as $key) {
        expect($direct['connections']['pgsql_direct'][$key])->toBe($direct['connections']['pgsql'][$key]);
    }
});

it('dumps the backup from the direct connection on pgsql', function (): void {
    $pgsql = loadConfigFileWith('backup', ['DB_CONNECTION' => 'pgsql']);
    $sqlite = loadConfigFileWith('backup', ['DB_CONNECTION' => 'sqlite']);

    expect($pgsql['backup']['source']['databases'])->toBe(['pgsql_direct'])
        ->and($sqlite['backup']['source']['databases'])->toBe(['sqlite']);
});

it('does not let the application DB_URL override the direct connection, only DB_DIRECT_URL', function (): void {
    $app = loadConfigFileWith('database', ['DB_URL' => 'pgsql://u:p@pgbouncer:5432/db', 'DB_DIRECT_URL' => null]);
    $direct = loadConfigFileWith('database', ['DB_URL' => 'pgsql://u:p@pgbouncer:5432/db', 'DB_DIRECT_URL' => 'pgsql://u:p@postgres:5432/db']);

    expect($app['connections']['pgsql']['url'])->toBe('pgsql://u:p@pgbouncer:5432/db')
        ->and($app['connections']['pgsql_direct']['url'])->toBeNull()
        ->and($direct['connections']['pgsql_direct']['url'])->toBe('pgsql://u:p@postgres:5432/db');
});
