<?php

declare(strict_types=1);

use App\Filament\Support\PanelTheme;
use Illuminate\Support\Facades\File;

/*
 * The theme of the panels (PanelTheme) is served from `public/`: `php artisan filament:assets` copies `resources/css/filament/panel.css`
 * to `public/css/app/lexiflow-panel.css` and `resources/fonts/panel/<font>` to `public/fonts/app/<font>`, and the copies are committed.
 * A source edited without running the command would ship the old theme; this test says so (docs/DEVOPS_GUIDE.md «Panel theme»).
 */

/** @return array<string, string> the files under `$dir`, relative path => contents */
function pta_files(string $dir): array
{
    $files = [];
    foreach (File::allFiles($dir) as $file) {
        $files[str_replace('\\', '/', $file->getRelativePathname())] = (string) file_get_contents($file->getPathname());
    }
    ksort($files);

    return $files;
}

it('publishes the stylesheet of the panels byte for byte', function (): void {
    $source = resource_path('css/filament/panel.css');
    $published = public_path('css/app/'.PanelTheme::CSS_ID.'.css');

    expect($published)->toBeFile()
        ->and(hash_file('sha256', $published))->toBe(hash_file('sha256', $source), 'public/css/app/lexiflow-panel.css differs from resources/css/filament/panel.css: run `php artisan filament:assets`');
});

it('publishes every font file of the panels byte for byte, and nothing else', function (string $font): void {
    $source = pta_files(resource_path('fonts/panel/'.$font));
    $published = pta_files(public_path('fonts/app/'.$font));

    expect($source)->not->toBe([])
        ->and(array_keys($published))->toBe(array_keys($source), "public/fonts/app/{$font} has other files than resources/fonts/panel/{$font}: run `php artisan filament:assets`");

    foreach ($source as $path => $contents) {
        expect(hash('sha256', $published[$path]))->toBe(hash('sha256', $contents), "public/fonts/app/{$font}/{$path} differs from its source: run `php artisan filament:assets`");
    }
})->with(['source-serif-4', 'ibm-plex-mono']);

it('checks every font the theme ships', function (): void {
    $fonts = array_map('basename', File::directories(resource_path('fonts/panel')));
    sort($fonts);

    expect($fonts)->toBe(['ibm-plex-mono', 'source-serif-4']);
});
