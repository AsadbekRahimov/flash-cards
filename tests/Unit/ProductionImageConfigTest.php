<?php

declare(strict_types=1);

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
