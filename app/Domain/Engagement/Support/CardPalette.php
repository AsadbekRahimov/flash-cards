<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

/**
 * The colours of the share cards (docs/36 §2б) - one place. A copy of the light-theme tokens of the Mini App
 * (`resources/twa/src/style.css`, the Muhrla kit, docs/14 §4.0): the paper panel on the canvas of the app, the brand and the
 * ring of the stamp in muhr, the texts in ink. A picture in a chat does not follow the theme of Telegram, so there is no dark
 * variant here. tests/Feature/Engagement/ShareCardTextsTest compares these with `style.css`, so a change of the tokens cannot
 * slip past the card.
 */
final class CardPalette
{
    /** The field around the panel and the inside of the stamp - `--app-page` (the canvas of the kit). */
    public const BACKGROUND = '#f6f2ea';

    /** The panel - `--app-surface` (the paper). */
    public const PANEL = '#fffdf8';

    /** The 1 px frame of the panel - `--app-hairline`. */
    public const LINE = '#e6dfd2';

    /** The brand in the header and the ring of the stamp (the medal) - `--app-muhr`. */
    public const MUHR = '#e4572e';

    /** The badge of the medal and the bot's address - `--app-accent` (the accent in text, 4.5:1 on paper). */
    public const ACCENT = '#b53a18';

    /** The title - `--app-text` (the ink). */
    public const TEXT = '#1a1a1f';

    /** The label, the description and the tagline - `--app-muted`. */
    public const MUTED = '#5e5a52';

    /** @var array<string, string> the token of `style.css` (light theme) each colour copies */
    public const TOKENS = [
        '--app-page' => self::BACKGROUND,
        '--app-surface' => self::PANEL,
        '--app-hairline' => self::LINE,
        '--app-muhr' => self::MUHR,
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
