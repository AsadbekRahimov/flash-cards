<?php

declare(strict_types=1);

use App\Domain\Learning\Services\SpellingGrader;

/*
 * docs/13 §2.5: OSA (restricted Damerau–Levenshtein) distance, tolerance by the length of the
 * expected text, normalization before comparing.
 */

beforeEach(function (): void {
    $this->grader = new SpellingGrader;
});

describe('distance (optimal string alignment)', function (): void {
    it('counts insertions, deletions, substitutions and adjacent transpositions as one edit each', function (): void {
        expect($this->grader->distance('abc', 'abc'))->toBe(0)
            ->and($this->grader->distance('abc', 'abcd'))->toBe(1)   // insertion
            ->and($this->grader->distance('abcd', 'abc'))->toBe(1)   // deletion
            ->and($this->grader->distance('abc', 'abx'))->toBe(1)    // substitution
            ->and($this->grader->distance('abcd', 'abdc'))->toBe(1)  // transposition — 2 for plain Levenshtein
            ->and($this->grader->distance('ca', 'abc'))->toBe(3)     // OSA, not unrestricted Damerau (which gives 2)
            ->and($this->grader->distance('', 'abc'))->toBe(3)
            ->and($this->grader->distance('abc', ''))->toBe(3)
            ->and($this->grader->distance('', ''))->toBe(0);
    });

    it('works in code points, not bytes', function (): void {
        expect($this->grader->distance('naïve', 'naive'))->toBe(1)
            ->and($this->grader->distance('résumé', 'resume'))->toBe(2);
    });
});

describe('tolerance by length', function (): void {
    it('allows 0 edits up to 4 characters, 1 for 5–8, 2 from 9', function (): void {
        expect($this->grader->tolerance(1))->toBe(0)
            ->and($this->grader->tolerance(4))->toBe(0)
            ->and($this->grader->tolerance(5))->toBe(1)
            ->and($this->grader->tolerance(8))->toBe(1)
            ->and($this->grader->tolerance(9))->toBe(2)
            ->and($this->grader->tolerance(30))->toBe(2);
    });

    it('grades exact = 5, within the tolerance = 4, beyond = 1', function (): void {
        // 4 letters: no slack at all.
        expect($this->grader->grade('book', 'book')->quality)->toBe(5)
            ->and($this->grader->grade('book', 'bok')->quality)->toBe(1)
            ->and($this->grader->grade('book', 'boko')->quality)->toBe(1);

        // 5–8 letters: one edit.
        expect($this->grader->grade('house', 'huose')->quality)->toBe(4)
            ->and($this->grader->grade('house', 'hose')->quality)->toBe(4)
            ->and($this->grader->grade('house', 'hos')->quality)->toBe(1)
            ->and($this->grader->grade('resilient', 'resilient')->quality)->toBe(5);

        // ≥ 9 letters: two edits.
        $verdict = $this->grader->grade('resilient', 'resiliant');
        expect($verdict->quality)->toBe(4)->and($verdict->distance)->toBe(1)->and($verdict->isCorrect())->toBeTrue();
        expect($this->grader->grade('resilient', 'reziliant')->quality)->toBe(4)   // 2 substitutions
            ->and($this->grader->grade('resilient', 'rezilliant')->quality)->toBe(1) // 3 edits
            ->and($this->grader->grade('resilient', '')->quality)->toBe(1);
    });

    it('measures the tolerance on the expected text, not on the answer', function (): void {
        // "cat" (3) → 0 edits even when the learner types something long.
        expect($this->grader->grade('cat', 'cats')->quality)->toBe(1);
    });
});

describe('normalization', function (): void {
    it('ignores case, outer whitespace and runs of inner whitespace', function (): void {
        expect($this->grader->grade('Ice cream', '  ICE   cream ')->quality)->toBe(5)
            ->and($this->grader->normalize("  Give\tUp \n"))->toBe('give up');
    });

    it('treats typographic apostrophes as the ASCII one', function (): void {
        expect($this->grader->grade("don't", 'don’t')->quality)->toBe(5)
            ->and($this->grader->grade("don't", 'don‘t')->quality)->toBe(5)
            ->and($this->grader->grade("o'clock", 'o`clock')->quality)->toBe(5);
    });

    it('applies NFKC: composed and decomposed accents, full-width letters compare equal', function (): void {
        expect($this->grader->grade('café', "cafe\u{0301}")->quality)->toBe(5)
            ->and($this->grader->grade('abc', "\u{FF41}\u{FF42}\u{FF43}")->quality)->toBe(5);
    })->skip(! extension_loaded('intl'), 'needs ext-intl');

    it('reports the normalized expected text for the client', function (): void {
        $verdict = $this->grader->grade('  Resilient ', 'x');
        expect($verdict->normalizedExpected)->toBe('resilient')
            ->and($verdict->normalizedAnswer)->toBe('x');
    });
});
