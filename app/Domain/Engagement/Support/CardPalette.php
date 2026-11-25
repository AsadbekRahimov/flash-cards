<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

/**
 * The colours of the share cards (docs/36 §2б) - one place. A copy of the light-theme tokens of the Mini App
 * (`resources/twa/src/style.css`): the card is the same blue style as the app. A picture in a chat does not follow the theme
 * of Telegram, so there is no dark variant. tests/Feature/Engagement/CardPaletteTest compares these with `style.css`, so a
 * change of the tokens cannot slip past the card.
 */
final class CardPalette
{
    /** The field around the panel - `--app-accent-soft`. */
    public const BACKGROUND = '#edf3ff';

    /** The panel - `--app-surface`. */
    public const PANEL = '#ffffff';

    /** The frame of the panel and of the medal - `--app-accent-line`. */
    public const LINE = '#c9dafb';

    /** The brand in the header - `--app-primary`. */
    public const PRIMARY = '#234fb1';

    /** The badge of the medal and the bot's address - `--app-accent`. */
    public const ACCENT = '#234fb1';

    /** The title - `--app-text`. */
    public const TEXT = '#151a28';

    /** The label, the description and the tagline - `--app-muted`. */
    public const MUTED = '#5a6275';

    /** @var array<string, string> the token of `style.css` (light theme) each colour copies */
    public const TOKENS = [
        '--app-accent-soft' => self::BACKGROUND,
        '--app-surface' => self::PANEL,
        '--app-accent-line' => self::LINE,
        '--app-primary' => self::PRIMARY,
        '--app-accent' => self::ACCENT,
        '--app-text' => self::TEXT,
        '--app-muted' => self::MUTED,
    ];

    /** @return array{int<0, 255>, int<0, 255>, int<0, 255>} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        /** @var array{int<0, 255>, int<0, 255>, int<0, 255>} */
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
