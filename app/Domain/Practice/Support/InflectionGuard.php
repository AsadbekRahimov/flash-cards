<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

/**
 * Tells a typo from a change of the grammatical form (docs/16 §5.2). A dictation may forgive ONE misspelt word, but "teacher"
 * for "teachers" or "liked" for "likes" is a different word as far as the lesson goes, not a slip of the finger: that is
 * a mistake, and the review says what the right form is (docs/15 §3.4: "another form of the word is not counted right
 * automatically").
 *
 * The caller has already found that the two tokens are one edit apart (`SpellingGrader::distance() = 1`), so a change of
 * form can only sit at the END of the word, and the rule looks at the tail alone (no stemmer: stripping `es` / `ed` made
 * `coffee` and `coffees` different stems and passed the plural as a "typo"). Let `x` be the shorter token and `y` the longer
 * (equal length: either); it is a change of form when
 *   (a) `y` is `x` + a final `s` or `d` - `teacher` / `teachers`, `coffee` / `coffees`, `like` / `liked`, `live` / `lived`;
 *   (b) the lengths are equal, the tokens are the same up to the last letter and those letters are `s` and `d` -
 *       `likes` / `liked`, `watches` / `watched`, `uses` / `used`.
 * Anything else is not. The strictness is intended: a doubtful case is a mistake. `friend` / `frien` (the final `d` is gone)
 * is a mistake by (a); a false "form" costs nothing - the answer only does not get the mark "typo".
 *
 * Both arguments are tokens of {@see PracticeTextNormalizer} (lower case).
 */
final class InflectionGuard
{
    public static function isInflection(string $a, string $e): bool
    {
        $a = mb_strtolower($a);
        $e = mb_strtolower($e);
        [$short, $long] = mb_strlen($a) <= mb_strlen($e) ? [$a, $e] : [$e, $a];

        // (a) a final `s` or `d` was added.
        if (mb_strlen($long) === mb_strlen($short) + 1 && ($long === $short.'s' || $long === $short.'d')) {
            return true;
        }

        // (b) the same word but for the last letter, which is `s` in one and `d` in the other.
        if (mb_strlen($long) === mb_strlen($short) && mb_substr($short, 0, -1) === mb_substr($long, 0, -1)) {
            $last = [mb_substr($short, -1), mb_substr($long, -1)];

            return in_array($last, [['s', 'd'], ['d', 's']], true);
        }

        return false;
    }
}
