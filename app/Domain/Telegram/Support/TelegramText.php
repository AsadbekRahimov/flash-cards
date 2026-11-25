<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Support;

/**
 * The length of a text as Telegram counts it: in UTF-16 code units (Bot API "1-1024 characters after entities parsing" - an emoji
 * outside the BMP is 2 units, a family emoji with joiners is ~11). `mb_strlen()` counts code points and lets a caption full of emoji
 * through that Telegram then refuses (400 "message caption is too long").
 *
 * {@see cut()} shortens by whole graphemes (`\X`): a flag, an emoji with its skin tone or a letter with its accents is never split.
 */
final class TelegramText
{
    /** The limit of a caption under a photo, a video, a document. */
    public const CAPTION_LIMIT = 1024;

    /** The limit of the text of a message. */
    public const MESSAGE_LIMIT = 4096;

    /** The length in UTF-16 code units. */
    public static function length(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }

        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function fits(string $text, int $limit): bool
    {
        // A code point is 1 or 2 units: the cheap bound first.
        return strlen($text) <= $limit || self::length($text) <= $limit;
    }

    /**
     * The text within `$limit` units: as it is when it fits, else its longest prefix of whole graphemes (trailing white space
     * dropped) and `$ellipsis`.
     */
    public static function cut(string $text, int $limit, string $ellipsis = '…'): string
    {
        if (self::fits($text, $limit)) {
            return $text;
        }

        $room = max(0, $limit - self::length($ellipsis));
        $out = '';
        $used = 0;
        foreach (self::graphemes($text) as $grapheme) {
            $units = self::length($grapheme);
            if ($used + $units > $room) {
                break;
            }
            $out .= $grapheme;
            $used += $units;
        }

        return rtrim($out).$ellipsis;
    }

    /** @return list<string> */
    public static function graphemes(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(\X)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $parts === false ? mb_str_split($text) : array_values($parts);
    }
}
