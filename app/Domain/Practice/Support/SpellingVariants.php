<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use RuntimeException;

/**
 * British and American spellings of one word (`colour` / `color`, `travelling` / `traveling`): the two are the SAME token for the
 * dictation (docs/16 §5.2), in both directions, so nobody has to list the other spelling in `accepted`. A pure function of the token and
 * of `resources/lexicon/en-us-gb.txt` (a pair `british american` to a line, only unambiguous pairs, every form listed by hand; read once
 * per process). Tokens come from {@see PracticeTextNormalizer::tokens()} (lower case, plain apostrophes).
 */
final class SpellingVariants
{
    /** Relative to `resources/`. */
    public const FILE = 'lexicon/en-us-gb.txt';

    /** @var array<string, string>|null a spelling (either one) => the other one */
    private static ?array $other = null;

    /** The same word: equal tokens, or the two spellings of a pair. */
    public static function same(string $a, string $b): bool
    {
        return $a === $b || (self::pairs()[$a] ?? null) === $b;
    }

    /** The other spelling of the token, null when it is in no pair. */
    public static function other(string $token): ?string
    {
        return self::pairs()[$token] ?? null;
    }

    /** @return array<string, string> */
    private static function pairs(): array
    {
        if (self::$other !== null) {
            return self::$other;
        }

        $path = resource_path(self::FILE);
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false) {
            throw new RuntimeException("The spelling list {$path} cannot be read.");
        }

        $other = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $words = preg_split('/\s+/', $line);
            if ($words === false || count($words) !== 2) {
                throw new RuntimeException("The spelling list {$path} has a malformed line: {$line}");
            }

            $other[$words[0]] = $words[1];
            $other[$words[1]] = $words[0];
        }

        return self::$other = $other;
    }
}
