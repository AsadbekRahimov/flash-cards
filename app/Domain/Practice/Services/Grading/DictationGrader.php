<?php

declare(strict_types=1);

namespace App\Domain\Practice\Services\Grading;

use App\Domain\Learning\Services\SpellingGrader;
use App\Domain\Practice\DTO\GradeResult;
use App\Domain\Practice\Enums\Verdict;
use App\Domain\Practice\Support\InflectionGuard;
use App\Domain\Practice\Support\PracticeTextNormalizer;

/**
 * Grades what the learner wrote against what was dictated (docs/16 §5.2): `correct`, `typo`, `wrong` or `skipped`.
 *
 * The text and each accepted variant are normalized the same way (`PracticeTextNormalizer`: case, spaces, apostrophes, the
 * hyphen, the listed punctuation). For every variant the verdict is worked out, the BEST one wins (correct > typo > wrong):
 *   1. the same tokens -> `correct`;
 *   2. `typo` only when ALL of this holds - the answer has as many tokens as the variant, exactly ONE token differs, the
 *      distance between the two (optimal string alignment, as in `SpellingGrader`) is 1, the expected token is at least
 *      `practice.grading.typo_min_length` long, the change is not one of the form of a word ({@see InflectionGuard}), and
 *      neither token is "protected": a word of `practice.grading.protected_tokens` (`not`, the numerals...), anything with
 *      `n't`, anything with a digit (a missing `not`, another number or `don't` / `dont` change the meaning);
 *   3. anything else -> `wrong`. A doubtful case is a mistake, with the right text shown.
 * Nothing written (or nothing but punctuation) is `skipped`: a hint and a mistake. A contraction is not the same as the long
 * form (`I'm` is not `I am`): only the variants the author lists are.
 *
 * `reveal` (docs/16 §5.2): `expected` - the first variant AS IT WAS GIVEN (the author's capitals and punctuation), `your_text` -
 * what was typed (200 characters at most), `diff` - token by token against the variant that fits best, only when the answer
 * has as many tokens as that variant (`[{expected, got, ok}]`, else null). `translation` and `explanation` belong to the
 * element, not to the text: the mode puts them in.
 */
final class DictationGrader
{
    /** The longest "your text" the review keeps. */
    public const MAX_TEXT = 200;

    public function __construct(private readonly SpellingGrader $spelling) {}

    /**
     * @param  list<string>  $expected  the accepted variants; the first is the one the review shows
     */
    public function grade(array $expected, string $answer): GradeResult
    {
        $shown = $expected === [] ? '' : trim((string) reset($expected));
        $yourText = trim(mb_substr(trim($answer), 0, self::MAX_TEXT));
        $answerTokens = PracticeTextNormalizer::tokens($yourText);

        if ($answerTokens === []) {
            return new GradeResult(Verdict::Skipped, $this->reveal($shown, null, null), ['skip' => true]);
        }

        $protected = array_flip(array_map('strval', (array) config('practice.grading.protected_tokens')));

        $best = null;
        foreach ($expected as $index => $variant) {
            $candidate = $this->evaluate($answerTokens, PracticeTextNormalizer::tokens($variant), $protected);
            // The best verdict; at an equal verdict the variant the answer is aligned with (as long), then the fewer misses, then the first.
            $key = [$this->rank($candidate['verdict']), $candidate['diff'] !== null ? 1 : 0, -$candidate['misses'], -$index];
            if ($best === null || $key > $best['key']) {
                $best = ['key' => $key, ...$candidate];
            }
        }

        $verdict = $best['verdict'] ?? Verdict::Wrong;

        return new GradeResult(
            verdict: $verdict,
            reveal: $this->reveal($shown, $yourText, $best['diff'] ?? null),
            normalizedAnswer: ['text' => $yourText],
            points: $verdict === Verdict::Correct ? max(0, (int) config('practice.battle.points.round', 100)) : 0,
        );
    }

    /**
     * @param  list<string>  $answer  the tokens of the answer
     * @param  list<string>  $variant  the tokens of one accepted variant
     * @param  array<string, int>  $protected  the protected tokens (as keys)
     * @return array{verdict: Verdict, diff: list<array{expected: string, got: string, ok: bool}>|null, misses: int}
     */
    private function evaluate(array $answer, array $variant, array $protected): array
    {
        if (count($answer) !== count($variant)) {
            return ['verdict' => Verdict::Wrong, 'diff' => null, 'misses' => PHP_INT_MAX];
        }

        $diff = [];
        $misses = [];
        foreach ($variant as $position => $expectedToken) {
            $got = $answer[$position];
            $diff[] = ['expected' => $expectedToken, 'got' => $got, 'ok' => $got === $expectedToken];
            if ($got !== $expectedToken) {
                $misses[] = $position;
            }
        }

        $verdict = match (true) {
            $misses === [] => Verdict::Correct,
            count($misses) === 1 && $this->isSafeTypo($answer[$misses[0]], $variant[$misses[0]], $protected) => Verdict::Typo,
            default => Verdict::Wrong,
        };

        return ['verdict' => $verdict, 'diff' => $diff, 'misses' => count($misses)];
    }

    /** correct > typo > wrong. */
    private function rank(Verdict $verdict): int
    {
        return match ($verdict) {
            Verdict::Correct => 3,
            Verdict::Typo => 2,
            default => 1,
        };
    }

    /**
     * One token written instead of another: a slip of the pen the dictation forgives?
     *
     * @param  array<string, int>  $protected
     */
    private function isSafeTypo(string $got, string $expected, array $protected): bool
    {
        return mb_strlen($expected) >= max(1, (int) config('practice.grading.typo_min_length'))
            && ! $this->isProtected($got, $protected)
            && ! $this->isProtected($expected, $protected)
            && $this->spelling->distance($got, $expected) === 1
            && ! InflectionGuard::isInflection($got, $expected);
    }

    /**
     * A token whose change changes the meaning: a listed word (`not`, a numeral), a negative contraction (`n't`), anything with a digit.
     *
     * @param  array<string, int>  $protected
     */
    private function isProtected(string $token, array $protected): bool
    {
        return isset($protected[$token]) || str_contains($token, "n't") || preg_match('/\d/u', $token) === 1;
    }

    /**
     * @param  list<array{expected: string, got: string, ok: bool}>|null  $diff
     * @return array{expected: string, your_text: string|null, diff: list<array{expected: string, got: string, ok: bool}>|null, translation: null, explanation: null}
     */
    private function reveal(string $expected, ?string $yourText, ?array $diff): array
    {
        return [
            'expected' => $expected,
            'your_text' => $yourText !== null && $yourText !== '' ? $yourText : null,
            'diff' => $diff,
            'translation' => null,
            'explanation' => null,
        ];
    }
}
