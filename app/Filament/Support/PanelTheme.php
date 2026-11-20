<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\FontProviders\LocalFontProvider;
use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Font;

/**
 * The look of both panels, `/admin` and `/org` (the design of 2026-11, docs/design/2026-11-redesign/Admin.html): calm, light,
 * no gradients and no shadows — white cards with a 1 px #E4E7EC border on #F6F7F9, a serif for the text and a monospace for the
 * numbers.
 *
 * How it is put together (Filament 5, no build step and no package of its own):
 *  - the palettes go through `->colors()`: Filament turns them into the CSS variables (`--primary-600`, `--gray-200`, ...) every
 *    component already reads, the dark mode included;
 *  - the fonts are files of the repository (`resources/fonts/panel/<font>`, SIL OFL, latin + latin-ext + cyrillic), registered as
 *    Filament font assets: `php artisan filament:assets` (run by `filament:upgrade` after every `composer install`) copies them to
 *    `public/fonts/app/<font>`, and `->font()` / `->monoFont()` link their `index.css` through the LOCAL provider — no font CDN;
 *  - what the variables cannot say (borders instead of shadows, the sidebar, the headings, the classes of the dashboard blocks)
 *    is one plain CSS file, `resources/css/filament/panel.css`, registered as a Filament asset of the panel the same way
 *    (copied to `public/css/app/lexiflow-panel.css`). Plain CSS on purpose: a Vite theme would make every panel page depend on
 *    a built manifest (and the tests on `npm run build`).
 */
final class PanelTheme
{
    public const CSS_ID = 'lexiflow-panel';

    public const SERIF = 'Source Serif 4';

    public const MONO = 'IBM Plex Mono';

    /** Ink and the cool grays of the design: #F6F7F9 the page, #E4E7EC the borders, #5F6677 the secondary text, #1D2130 the text. */
    public const GRAY = [
        50 => '#F6F7F9',
        100 => '#EEF0F4',
        200 => '#E4E7EC',
        300 => '#CDD2DC',
        400 => '#9AA1B1',
        500 => '#5F6677',
        600 => '#4A5162',
        700 => '#383E4D',
        800 => '#2A2F3C',
        900 => '#222632',
        950 => '#1D2130',
    ];

    /** The accent #3F4A7A and the primary button #2B3252 (the 600 a button is painted with, the 500 of its hover). */
    public const PRIMARY = [
        50 => '#F0F1F7',
        100 => '#E3E5F0',
        200 => '#C9CEE2',
        300 => '#A6ADCF',
        400 => '#8B93BD',
        500 => '#3F4A7A',
        600 => '#2B3252',
        700 => '#242A45',
        800 => '#1E2339',
        900 => '#181C2E',
        950 => '#10131F',
    ];

    /** Green #2F7D5B / #1F5A40 on #EEF6F1. */
    public const SUCCESS = [
        50 => '#EEF6F1',
        100 => '#DCEDE3',
        200 => '#BBDCC9',
        300 => '#8FC4A6',
        400 => '#5FA582',
        500 => '#3F8C69',
        600 => '#2F7D5B',
        700 => '#1F5A40',
        800 => '#1A4A35',
        900 => '#153D2C',
        950 => '#0B2219',
    ];

    /** Amber #B4741A / #8A5510 on #F7EEDD. */
    public const WARNING = [
        50 => '#F7EEDD',
        100 => '#F1E1C2',
        200 => '#E7C88F',
        300 => '#DBAA5C',
        400 => '#CC9036',
        500 => '#C17F22',
        600 => '#B4741A',
        700 => '#8A5510',
        800 => '#70450F',
        900 => '#5C3A10',
        950 => '#341F06',
    ];

    /** Red #B4382C / #8C2A21 on #FBF1EF. */
    public const DANGER = [
        50 => '#FBF1EF',
        100 => '#F6E0DC',
        200 => '#EDC0B9',
        300 => '#E0968B',
        400 => '#CF6B5E',
        500 => '#C24A3D',
        600 => '#B4382C',
        700 => '#8C2A21',
        800 => '#73231C',
        900 => '#5F201A',
        950 => '#330E0B',
    ];

    /** Muted blue for informational badges, from the chart of the design (#1F6FA8). */
    public const INFO = [
        50 => '#EEF4FA',
        100 => '#DAE7F4',
        200 => '#B6CFE8',
        300 => '#86B0D7',
        400 => '#538DC2',
        500 => '#2F7AB5',
        600 => '#1F6FA8',
        700 => '#1A5784',
        800 => '#17496C',
        900 => '#153D5A',
        950 => '#0D263A',
    ];

    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->colors([
                'primary' => self::PRIMARY,
                'gray' => self::GRAY,
                'success' => self::SUCCESS,
                'warning' => self::WARNING,
                'danger' => self::DANGER,
                'info' => self::INFO,
            ])
            ->font(
                self::SERIF,
                url: fn (): string => asset('fonts/app/source-serif-4/index.css'),
                provider: LocalFontProvider::class,
                preload: fn (): array => [asset('fonts/app/source-serif-4/source-serif-4-latin-400-normal.woff2'), asset('fonts/app/source-serif-4/source-serif-4-cyrillic-400-normal.woff2')],
            )
            ->monoFont(
                self::MONO,
                url: fn (): string => asset('fonts/app/ibm-plex-mono/index.css'),
                provider: LocalFontProvider::class,
            )
            ->assets([
                Font::make('source-serif-4', resource_path('fonts/panel/source-serif-4')),
                Font::make('ibm-plex-mono', resource_path('fonts/panel/ibm-plex-mono')),
                Css::make(self::CSS_ID, resource_path('css/filament/panel.css')),
            ]);
    }
}
