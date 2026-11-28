<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * What the production image does at start and how its PHP is tuned (docs/20 §1.12 F1, docs/19 C7). The image itself
 * is built by CI; these tests keep the files it is built from from drifting apart.
 */

/** @return string the file under the project root */
function prodImageFile(string $path): string
{
    $contents = file_get_contents(base_path($path));
    assert(is_string($contents));

    return $contents;
}

it('caches the Filament components in the entrypoint, in production only and after view:cache', function (): void {
    $entrypoint = prodImageFile('docker/entrypoint.sh');

    expect(preg_match('/if \[ "\$\{APP_ENV:-production\}" = "production" \]; then(.*?)\nfi\n/s', $entrypoint, $block))->toBe(1);

    $commands = array_values(array_filter(array_map('trim', explode("\n", $block[1])), fn (string $line): bool => str_starts_with($line, 'php artisan')));

    expect($commands)->toContain('php artisan filament:optimize --no-interaction');

    $viewCache = array_search('php artisan view:cache --no-interaction', $commands, true);
    $filament = array_search('php artisan filament:optimize --no-interaction', $commands, true);

    expect($viewCache)->toBeInt()->and($filament)->toBeGreaterThan($viewCache);

    // Outside the production block nothing runs filament:optimize (the cache would hide new resources in development).
    expect(substr_count($entrypoint, 'filament:optimize'))->toBe(1);

    // The api surface (the app-api container) registers no Filament: both Blade-related caches are skipped there, inside
    // the production block, and nothing else of the block is.
    expect(preg_match('/if \[ "\$SURFACE" != "api" \]; then(.*?)\n\s*fi\n/s', $block[1], $apiSkipped))->toBe(1);
    expect($apiSkipped[1])->toContain('php artisan view:cache --no-interaction')
        ->and($apiSkipped[1])->toContain('php artisan filament:optimize --no-interaction')
        ->and($apiSkipped[1])->not->toContain('config:cache')
        ->and($apiSkipped[1])->not->toContain('route:cache')
        ->and($apiSkipped[1])->not->toContain('event:cache');
});

it('compiles the Blade views of each container outside the shared storage volume, set before config:cache, in production only', function (): void {
    $entrypoint = prodImageFile('docker/entrypoint.sh');

    expect(preg_match('/if \[ "\$\{APP_ENV:-production\}" = "production" \]; then(.*?)\nfi\n/s', $entrypoint, $block))->toBe(1);

    // The default path: under bootstrap/cache (not a volume, owned by `app`), never under storage/ (the shared `app_storage`).
    expect(preg_match('/^\s*export VIEW_COMPILED_PATH="\$\{VIEW_COMPILED_PATH:-(?<path>[^}"]+)\}"$/m', $block[1], $export))->toBe(1);
    expect($export['path'])->toStartWith('/var/www/html/bootstrap/cache/')
        ->and($export['path'])->not->toContain('storage');

    // Exported and created before config:cache bakes it into the config cache (php-fpm and Octane read it from there).
    $exportAt = strpos($block[1], 'export VIEW_COMPILED_PATH=');
    $mkdirAt = strpos($block[1], 'mkdir -p "$VIEW_COMPILED_PATH"');
    $configCacheAt = strpos($block[1], 'php artisan config:cache');
    expect($mkdirAt)->toBeGreaterThan($exportAt)
        ->and($configCacheAt)->toBeGreaterThan($mkdirAt);

    // Outside the production block the variable is not touched: development keeps storage/framework/views.
    expect(substr_count($entrypoint, 'VIEW_COMPILED_PATH='))->toBe(1);
});

it('runs every artisan command of the production start with VIEW_COMPILED_PATH set, and none in development', function (): void {
    $dir = sys_get_temp_dir().'/lf-entrypoint-'.bin2hex(random_bytes(4));
    mkdir("{$dir}/bin", 0o755, true);
    // A fake php: logs the command and the path it sees, exits 0.
    file_put_contents("{$dir}/bin/php", "#!/bin/sh\necho \"\$* VIEW=\${VIEW_COMPILED_PATH-unset}\" >> \"{$dir}/php.log\"\n");
    chmod("{$dir}/bin/php", 0o755);

    $run = fn (array $env) => Process::path($dir)->env(['PATH' => "{$dir}/bin:".getenv('PATH'), ...$env])
        ->run(['sh', base_path('docker/entrypoint.sh'), 'sh', '-c', 'echo "CMD VIEW=${VIEW_COMPILED_PATH-unset}"']);

    try {
        $views = "{$dir}/compiled-views";
        $production = $run(['APP_ENV' => 'production', 'APP_SURFACE' => 'full', 'VIEW_COMPILED_PATH' => $views]);

        expect($production->successful())->toBeTrue()
            ->and(is_dir($views))->toBeTrue()
            ->and($production->output())->toContain("CMD VIEW={$views}");

        $calls = array_filter(explode("\n", (string) file_get_contents("{$dir}/php.log")));
        // config, route, view, filament:optimize, event (the full surface).
        expect($calls)->toHaveCount(5);
        foreach ($calls as $call) {
            expect($call)->toEndWith("VIEW={$views}");
        }

        unlink("{$dir}/php.log");
        $development = $run(['APP_ENV' => 'local']);

        expect($development->successful())->toBeTrue()
            ->and($development->output())->toContain('CMD VIEW=unset')
            ->and(file_exists("{$dir}/php.log"))->toBeFalse();
    } finally {
        Process::run(['rm', '-rf', $dir]);
    }
});

it('clears the Filament cache with the development cache target', function (): void {
    expect(prodImageFile('Makefile'))->toMatch('/^cache-clear:.*\n\t\$\(ARTISAN\) optimize:clear\n\t\$\(ARTISAN\) filament:optimize-clear\n/m');
});

it('tunes OPcache for immutable code in the production ini', function (): void {
    $ini = parse_ini_file(base_path('docker/php/opcache-prod.ini'), false, INI_SCANNER_TYPED);

    expect($ini)->toBeArray()
        ->and($ini['opcache.enable'])->toBe(1)
        ->and($ini['opcache.validate_timestamps'])->toBe(0)
        ->and($ini['opcache.save_comments'])->toBe(1)
        ->and($ini['opcache.memory_consumption'])->toBeGreaterThanOrEqual(192)
        ->and($ini['opcache.interned_strings_buffer'])->toBeGreaterThanOrEqual(32);
});

it('keeps max_accelerated_files above the number of PHP files of vendor and app', function (): void {
    $ini = parse_ini_file(base_path('docker/php/opcache-prod.ini'), false, INI_SCANNER_TYPED);
    assert(is_array($ini));

    $files = 0;
    foreach (['vendor', 'app'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files++;
            }
        }
    }

    // The hash table of OPcache needs room: stay at least 1.5x above what the tree holds (compiled Blade views come on top).
    expect($files)->toBeGreaterThan(1000)
        ->and($ini['opcache.max_accelerated_files'])->toBeGreaterThanOrEqual((int) ($files * 1.5));
});

it('gives only the production image the OPcache ini and fails its build when the settings are not applied', function (): void {
    $dockerfile = prodImageFile('Dockerfile');

    expect($dockerfile)
        ->toContain('COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache-prod.ini')
        ->toContain('opcache.validate_timestamps => Off')
        ->toContain('opcache.save_comments => On');

    expect(prodImageFile('Dockerfile.dev'))->not->toContain('opcache-prod');
});
