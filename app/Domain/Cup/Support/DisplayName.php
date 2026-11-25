<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

/**
 * The name of a participant in a cup (docs/33 §2.3, §4.7): it is published in the chat, so no control or format characters (bidi
 * overrides, zero-width joiners), white space folded, 1-32 characters. HTML is escaped where it is sent, not here.
 */
final class DisplayName
{
    public const MAX = 32;

    /** The clean name, or null when nothing printable is left or it is longer than {@see MAX}. */
    public static function normalize(?string $name): ?string
    {
        if ($name === null || ! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}\p{Zl}\p{Zp}]+/u', '', $name);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        if ($clean === '' || mb_strlen($clean) > self::MAX) {
            return null;
        }

        return $clean;
    }

    /** The default name from the Telegram first name: cleaned and cut to {@see MAX}; a fallback when nothing is left. */
    public static function fromFirstName(?string $firstName, int $learnerId): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}\p{Co}\p{Cs}\p{Zl}\p{Zp}]+/u', '', (string) $firstName)));
        $clean = trim(mb_substr($clean, 0, self::MAX));

        return $clean !== '' ? $clean : 'Player '.$learnerId;
    }
}
