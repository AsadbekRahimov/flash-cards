<?php

declare(strict_types=1);

namespace App\Domain\Engagement\Support;

/**
 * The colours of the share cards (docs/36 §2б, docs/40 §2.4) - one place. A copy of the tokens of the Mini App
 * (`resources/twa/src/style.css`, the Muhrla kit, docs/14 §4.0): the 1:1 card is the paper panel on the canvas of the light theme -
 * the brand and the ring of the stamp in muhr, the texts in ink; the 9:16 story card is the ink of the kit (`--app-inverse`) with
 * the paper on it and the muhr of the dark pair (made for dark surfaces). A picture in a chat does not follow the theme of Telegram,
 * so each card has one fixed set. tests/Feature/Engagement/ShareCardTextsTest compares these with `style.css`, so a change of the
 * tokens cannot slip past the cards.
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

    /** The story (docs/40 §2.4, F3): the whole canvas is the ink - `--app-inverse` of the light theme (#1A1A1F of the kit's mock-up). */
    public const STORY_BACKGROUND = '#1a1a1f';

    /** The title and the bot's address on the ink - `--app-on-inverse` (the paper). */
    public const STORY_TEXT = '#fffdf8';

    /** The description on the ink - `--app-muted` of the dark pair. */
    public const STORY_MUTED = '#b4aea3';

    /** The ring of the stamp and the brand on the ink - `--app-muhr` of the dark pair (the light muhr is made for paper). */
    public const STORY_MUHR = '#ff6b42';

    /** @var array<string, string> the token of `style.css` (light theme) each colour of the 1:1 card copies */
    public const TOKENS = [
        '--app-page' => self::BACKGROUND,
        '--app-surface' => self::PANEL,
        '--app-hairline' => self::LINE,
        '--app-muhr' => self::MUHR,
        '--app-accent' => self::ACCENT,
        '--app-text' => self::TEXT,
        '--app-muted' => self::MUTED,
    ];

    /** @var list<array{token: string, theme: 'light'|'dark', hex: string}> the tokens the story card copies, and from which theme */
    public const STORY_TOKENS = [
        ['token' => '--app-inverse', 'theme' => 'light', 'hex' => self::STORY_BACKGROUND],
        ['token' => '--app-on-inverse', 'theme' => 'light', 'hex' => self::STORY_TEXT],
        ['token' => '--app-muted', 'theme' => 'dark', 'hex' => self::STORY_MUTED],
        ['token' => '--app-muhr', 'theme' => 'dark', 'hex' => self::STORY_MUHR],
    ];

    /** @return array{int<0, 255>, int<0, 255>, int<0, 255>} */
    public static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        /** @var array{int<0, 255>, int<0, 255>, int<0, 255>} */
        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
