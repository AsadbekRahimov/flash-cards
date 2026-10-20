<?php

declare(strict_types=1);

namespace App\Domain\Learning\Services;

use App\Domain\Learning\DTO\SpellingVerdict;
use Normalizer;

/**
 * Grades a typed answer against the expected text (docs/13 §2.5, docs/06 §4.3).
 *
 * Both texts are normalized first (trim, NFKC when the intl extension is there, lower case,
 * typographic apostrophes → `'`, runs of whitespace → one space), then compared by the optimal
 * string alignment distance (Damerau–Levenshtein with adjacent transpositions, each edit = 1).
 * The tolerance grows with the length of the expected text: ≤ 4 characters → exact only,
 * 5–8 → one edit, ≥ 9 → two. Exact match → quality 5, within the tolerance → 4, otherwise 1
 * (a fail for SM-2: the card goes back to its learning steps).
 */
final class SpellingGrader
{
    public const QUALITY_EXACT = 5;

    public const QUALITY_CLOSE = 4;

    public const QUALITY_WRONG = 1;

    private const APOSTROPHES = ["\u{2019}", "\u{2018}", "\u{02BC}", "\u{00B4}", '`'];

    public function grade(string $expected, string $answer): SpellingVerdict
    {
        $expected = $this->normalize($expected);
        $answer = $this->normalize($answer);

        if ($expected === $answer) {
            return new SpellingVerdict(self::QUALITY_EXACT, 0, $expected, $answer);
        }

        $distance = $this->distance($expected, $answer);
        $quality = $distance <= $this->tolerance(mb_strlen($expected)) ? self::QUALITY_CLOSE : self::QUALITY_WRONG;

        return new SpellingVerdict($quality, $distance, $expected, $answer);
    }

    public function normalize(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $text = $normalized;
            }
        }

        $text = str_replace(self::APOSTROPHES, "'", $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strtolower(trim($text));
    }

    /** Edits allowed for an expected text of this length (docs/13 §2.5). */
    public function tolerance(int $length): int
    {
        return match (true) {
            $length <= 4 => 0,
            $length <= 8 => 1,
            default => 2,
        };
    }

    /** Optimal string alignment distance (restricted Damerau–Levenshtein), in code points. */
    public function distance(string $a, string $b): int
    {
        $x = $a === '' ? [] : mb_str_split($a);
        $y = $b === '' ? [] : mb_str_split($b);
        $n = count($x);
        $m = count($y);

        if ($n === 0) {
            return $m;
        }
        if ($m === 0) {
            return $n;
        }

        /** @var array<int, array<int, int>> $d */
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $d[$i][$j] = min(
                    $d[$i - 1][$j] + 1,
                    $d[$i][$j - 1] + 1,
                    $d[$i - 1][$j - 1] + $cost,
                );
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $d[$n][$m];
    }
}
