<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/**
 * A config file as production reads it, with the given environment (null = the variable is absent). Every source
 * Laravel's env() reads ($_SERVER, $_ENV, getenv) is put back exactly afterwards.
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function backupConfigWith(string $file, array $env): array
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

/** @return array<string, string|null> */
function backupDiskEnv(): array
{
    return [
        'BACKUP_ACCESS_KEY_ID' => 'key-id',
        'BACKUP_SECRET_ACCESS_KEY' => 'key-secret',
        'BACKUP_DEFAULT_REGION' => null,
        'BACKUP_BUCKET' => 'lexiflow-backups',
        'BACKUP_ENDPOINT' => 'https://acc.r2.cloudflarestorage.com',
        'BACKUP_USE_PATH_STYLE_ENDPOINT' => null,
    ];
}

function scheduledBackupEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $e): bool => str_contains((string) $e->command, " {$command}"));
}

it('maps every BACKUP_* variable onto the backups disk', function (): void {
    $disk = backupConfigWith('filesystems', backupDiskEnv())['disks']['backups'];

    expect($disk)->toMatchArray([
        'driver' => 's3',
        'key' => 'key-id',
        'secret' => 'key-secret',
        'region' => 'auto',
        'bucket' => 'lexiflow-backups',
        'endpoint' => 'https://acc.r2.cloudflarestorage.com',
        'use_path_style_endpoint' => false,
        'visibility' => 'private',
        'throw' => true,
        'report' => false,
    ])->and($disk)->not->toHaveKey('url');
});

it('takes the region and the path style from the environment when they are set', function (): void {
    $disk = backupConfigWith('filesystems', [
        ...backupDiskEnv(),
        'BACKUP_DEFAULT_REGION' => 'eu',
        'BACKUP_USE_PATH_STYLE_ENDPOINT' => 'true',
    ])['disks']['backups'];

    expect($disk['region'])->toBe('eu')
        ->and($disk['use_path_style_endpoint'])->toBeTrue();
});

it('builds an S3 adapter for the backups disk without touching the network', function (): void {
    config([
        'filesystems.disks.backups' => [
            'driver' => 's3',
            'key' => 'key-id',
            'secret' => 'key-secret',
            'region' => 'auto',
            'bucket' => 'lexiflow-backups',
            'endpoint' => 'https://acc.r2.cloudflarestorage.com',
            'visibility' => 'private',
            'throw' => true,
        ],
    ]);

    $adapter = Storage::disk('backups')->getAdapter();

    expect($adapter)->toBeInstanceOf(AwsS3V3Adapter::class);
});

it('parses BACKUP_DISKS: trimmed, without empty entries, local by default', function (?string $value, array $expected): void {
    $config = backupConfigWith('backup', ['BACKUP_DISKS' => $value]);

    expect($config['backup']['destination']['disks'])->toBe($expected)
        ->and($config['monitor_backups'][0]['disks'])->toBe($expected);
})->with([
    'spaces around entries' => [' local , backups ', ['local', 'backups']],
    'empty' => ['', ['local']],
    'only separators' => [' , ,', ['local']],
    'absent' => [null, ['local']],
    'off-site only' => ['backups', ['backups']],
]);

it('schedules the database backup hourly at minute 20, isolated and without overlap', function (): void {
    $event = scheduledBackupEvent('backup:run');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 * * * *')
        ->and($event->command)->toContain('--only-db')->toContain('--isolated')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(55);
});

it('schedules the cleanup daily at 01:00 and the monitor daily at 02:00', function (): void {
    $clean = scheduledBackupEvent('backup:clean');
    $monitor = scheduledBackupEvent('backup:monitor');

    expect($clean)->not->toBeNull()
        ->and($clean->expression)->toBe('0 1 * * *')
        ->and($clean->withoutOverlapping)->toBeTrue()
        ->and($monitor)->not->toBeNull()
        ->and($monitor->expression)->toBe('0 2 * * *');
});

it('keeps the migrate service taking a non-fatal restore point first', function (): void {
    $compose = (string) file_get_contents(base_path('docker-compose.coolify.yml'));

    expect($compose)->toContain("(php artisan backup:run --only-db --isolated || echo 'WARNING backup:run failed') && php artisan migrate --force");
});
