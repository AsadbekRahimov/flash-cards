<?php

declare(strict_types=1);

namespace App\Domain\Cup\Support;

use App\Domain\Cup\Exceptions\CupException;
use App\Domain\Telegram\Support\TelegramText;
use Normalizer;

/**
 * The name of a participant in a cup (docs/33 §2.3, §4.7): the bot publishes it in the chat as PLAIN text, and Telegram turns parts
 * of a plain text into links by itself - URLs and domains, `@username`, `/command`, `#hashtag`, `$CASHTAG`. A name must never become
 * one: a participant would advertise a channel or a site to the whole chat under the bot's name.
 *
 * {@see validate()} - what a participant types (join, rename): NFC, the invisible characters dropped (control and format characters
 * incl. the bidi overrides U+202A-U+202E / U+2066-U+2069, zero-width spaces, the Hangul and Braille fillers; a zero-width joiner
 * stays only inside an emoji sequence), white space folded; then refused (422 `cup_display_name_invalid {reason}`) when:
 *   - `empty`    nothing printable is left;
 *   - `too_long` more than {@see MAX} graphemes (what a person sees as one character) or {@see MAX_CODE_POINTS} code points;
 *   - `marks`    more than {@see MAX_MARKS} combining marks in a row ("Zalgo" text that overflows the lines around it);
 *   - `link`     `://`, `www.`, or a dot between a letter or digit and a letter (`site.com`, `t.me/x`, `J.R` - the simple rule);
 *   - `mention`  `@` at the start of a word;
 *   - `command`  `/` at the start of a word;
 *   - `tag`      `#` at the start of a word with a letter in it, `$` at the start of a word before a Latin letter.
 *
 * {@see forChat()} - what the bot prints: the same cleaning, and every such pattern DEFUSED instead of refused (a look-alike
 * character Telegram does not link: `．`-like U+2024 for the dot, `＠`, `∕`, `＃`, `＄`, U+2044 for the slashes of `://`) - names stored
 * before these rules, and the Telegram first name a participant joins with ({@see fromFirstName()}).
 */
final class DisplayName
{
    /** Graphemes. */
    public const MAX = 32;

    /** Code points: the column `cup_participants.display_name` (a grapheme of an emoji sequence is up to ~10 of them). */
    public const MAX_CODE_POINTS = 64;

    /** Combining marks in a row (a letter with two accents is fine). */
    public const MAX_MARKS = 2;

    public const REASON_EMPTY = 'empty';

    public const REASON_TOO_LONG = 'too_long';

    public const REASON_MARKS = 'marks';

    public const REASON_LINK = 'link';

    public const REASON_MENTION = 'mention';

    public const REASON_COMMAND = 'command';

    public const REASON_TAG = 'tag';

    /** Format / control / private / surrogate / line separators, except the zero-width joiner (decided apart). */
    private const INVISIBLE = '/(?!\x{200D})[\p{Cc}\p{Cf}\p{Co}\p{Cs}\p{Zl}\p{Zp}]|[\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{2800}\x{3164}\x{FE00}-\x{FE0E}\x{FFA0}\x{E0100}-\x{E01EF}]/u';

    /** A zero-width joiner that does not join two parts of an emoji. */
    private const LOOSE_JOINER = '/(?<![\p{So}\p{Sk}\x{FE0F}])\x{200D}|\x{200D}(?!\p{So})/u';

    /** reason => pattern, in the order they are reported. */
    private const RULES = [
        self::REASON_LINK => '~://|\bwww\.|[\p{L}\p{N}]\.\p{L}~iu',
        self::REASON_MENTION => '/(?<![\p{L}\p{N}_])@[\p{L}\p{N}_]/u',
        self::REASON_COMMAND => '~(?<![\p{L}\p{N}_/])/[\p{L}\p{N}_]~u',
        self::REASON_TAG => '/(?<![\p{L}\p{N}_])(?:#\p{N}*[\p{L}_]|\$[A-Za-z])/u',
    ];

    /**
     * The clean name a participant typed, or the refusal with its reason.
     *
     * @throws CupException cup_display_name_invalid {reason}
     */
    public static function validate(?string $name): string
    {
        $clean = self::clean($name);
        if ($clean === '') {
            throw CupException::displayNameInvalid(self::REASON_EMPTY);
        }
        if (self::graphemeCount($clean) > self::MAX || mb_strlen($clean) > self::MAX_CODE_POINTS) {
            throw CupException::displayNameInvalid(self::REASON_TOO_LONG);
        }
        if (preg_match('/\p{Mn}{'.(self::MAX_MARKS + 1).',}/u', $clean) === 1) {
            throw CupException::displayNameInvalid(self::REASON_MARKS);
        }
        foreach (self::RULES as $reason => $pattern) {
            if (preg_match($pattern, $clean) === 1) {
                throw CupException::displayNameInvalid($reason);
            }
        }

        return $clean;
    }

    /** {@see validate()} without the reason: the clean name, or null. */
    public static function normalize(?string $name): ?string
    {
        try {
            return self::validate($name);
        } catch (CupException) {
            return null;
        }
    }

    /**
     * A name as the bot prints it in the chat: cleaned, defused, at most {@see MAX} graphemes. A valid name comes back unchanged.
     */
    public static function forChat(string $name): string
    {
        $safe = self::defuse(self::clean($name));

        return self::limit($safe);
    }

    /** The default name from the Telegram first name: cleaned, defused and cut; a fallback when nothing is left. */
    public static function fromFirstName(?string $firstName, int $learnerId): string
    {
        $safe = self::limit(self::defuse(self::clean($firstName)));

        return $safe !== '' ? $safe : 'Player '.$learnerId;
    }

    public static function graphemeCount(string $text): int
    {
        return count(TelegramText::graphemes($text));
    }

    /** NFC, the invisible characters dropped, white space folded and trimmed; '' for nothing or broken UTF-8. */
    private static function clean(?string $name): string
    {
        if ($name === null || $name === '' || ! mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        if (class_exists(Normalizer::class)) {
            $name = Normalizer::normalize($name, Normalizer::FORM_C);
            if (! is_string($name)) {
                return '';
            }
        }
        $name = (string) preg_replace(self::INVISIBLE, '', $name);
        $name = (string) preg_replace(self::LOOSE_JOINER, '', $name);

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $name));
    }

    /** Every pattern Telegram would link, replaced by a look-alike it does not; marks capped at {@see MAX_MARKS} in a row. */
    private static function defuse(string $name): string
    {
        $name = (string) preg_replace('/(\p{Mn}{'.self::MAX_MARKS.'})\p{Mn}+/u', '$1', $name);

        return (string) preg_replace(
            [
                '~:/{2,}~u',                                // :// → :⁄⁄
                '/([\p{L}\p{N}])\.(?=\p{L})/u',             // site.com, www.x, t.me → one dot leader
                '/(?<![\p{L}\p{N}_])@(?=[\p{L}\p{N}_])/u',  // @user
                '~(?<![\p{L}\p{N}_/])/(?=[\p{L}\p{N}_])~u', // /command
                '/(?<![\p{L}\p{N}_])#(?=\p{N}*[\p{L}_])/u', // #tag
                '/(?<![\p{L}\p{N}_])\$(?=[A-Za-z])/u',      // $CASH
            ],
            [':⁄⁄', '$1․', '＠', '∕', '＃', '＄'],
            $name,
        );
    }

    /** At most {@see MAX} graphemes and {@see MAX_CODE_POINTS} code points, cut between graphemes. */
    private static function limit(string $name): string
    {
        if (self::graphemeCount($name) <= self::MAX && mb_strlen($name) <= self::MAX_CODE_POINTS) {
            return $name;
        }
        $out = '';
        $count = 0;
        foreach (TelegramText::graphemes($name) as $grapheme) {
            if ($count + 1 > self::MAX || mb_strlen($out.$grapheme) > self::MAX_CODE_POINTS) {
                break;
            }
            $out .= $grapheme;
            $count++;
        }

        return trim($out);
    }
}
