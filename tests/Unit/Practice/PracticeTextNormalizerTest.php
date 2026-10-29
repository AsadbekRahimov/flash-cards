<?php

declare(strict_types=1);

use App\Domain\Practice\Support\PracticeTextNormalizer;

/*
 * docs/16 §5.1: the one normalization of the texts the practice compares.
 */

it('lowers the case, drops the punctuation and collapses the white space', function (string $input, string $expected): void {
    expect(PracticeTextNormalizer::normalize($input))->toBe($expected);
})->with([
    'case and !' => ['See You Tomorrow!', 'see you tomorrow'],
    'spaces' => ['See  you   tomorrow', 'see you tomorrow'],
    'tabs and new lines' => ["  See\tyou\ntomorrow \r\n", 'see you tomorrow'],
    'full list of punctuation' => ['(a) [b]; c: d… e? f, g. h!', 'a b c d e f g h'],
    'punctuation only' => ['?!. ,;:', ''],
    'empty' => ['', ''],
    'Cyrillic' => ['ПРИВЕТ, Мир!', 'привет мир'],
    'digits stay' => ["It's 5 o'clock.", "it's 5 o'clock"],
]);

it('turns every kind of apostrophe into a plain one and keeps it inside the token', function (string $apostrophe): void {
    expect(PracticeTextNormalizer::normalize("Don{$apostrophe}t know"))->toBe("don't know");
})->with([
    'right single quote' => ["\u{2019}"],
    'left single quote' => ["\u{2018}"],
    'modifier letter apostrophe' => ["\u{02BC}"],
    'acute accent' => ["\u{00B4}"],
    'backtick' => ['`'],
    'prime' => ["\u{2032}"],
    'plain' => ["'"],
]);

it('deletes the double quotes of every kind', function (string $text): void {
    expect(PracticeTextNormalizer::normalize($text))->toBe('say hello');
})->with([
    'straight' => ['say "hello"'],
    'guillemets' => ['say «hello»'],
    'typographic' => ["say \u{201C}hello\u{201D}"],
    'low-9' => ["say \u{201E}hello\u{201D}"],
]);

it('treats hyphens and dashes as a space: well-known = well known', function (string $dash): void {
    expect(PracticeTextNormalizer::normalize("ice{$dash}cream"))->toBe('ice cream');
})->with([
    'hyphen-minus' => ['-'],
    'hyphen' => ["\u{2010}"],
    'non-breaking hyphen' => ["\u{2011}"],
    'figure dash' => ["\u{2012}"],
    'en dash' => ["\u{2013}"],
    'em dash' => ["\u{2014}"],
    'horizontal bar' => ["\u{2015}"],
]);

it('applies NFKC: full-width letters and ligatures become plain ones', function (): void {
    expect(PracticeTextNormalizer::normalize("\u{FF33}\u{FF45}\u{FF45}"))->toBe('see')   // full-width "See"
        ->and(PracticeTextNormalizer::normalize("\u{FB01}ne"))->toBe('fine');             // the "fi" ligature
});

it('does not equate contractions with the long forms: only the variants of the author do', function (): void {
    expect(PracticeTextNormalizer::normalize("I'm"))->not->toBe(PracticeTextNormalizer::normalize('I am'))
        ->and(PracticeTextNormalizer::normalize("don't"))->not->toBe(PracticeTextNormalizer::normalize('do not'))
        ->and(PracticeTextNormalizer::normalize("It's five o'clock"))->not->toBe(PracticeTextNormalizer::normalize('its five oclock'));
});

it('splits into tokens, and an empty text has none', function (): void {
    expect(PracticeTextNormalizer::tokens("I don't know."))->toBe(['i', "don't", 'know'])
        ->and(PracticeTextNormalizer::tokens('Ice-cream, please!'))->toBe(['ice', 'cream', 'please'])
        ->and(PracticeTextNormalizer::tokens(''))->toBe([])
        ->and(PracticeTextNormalizer::tokens("  \t "))->toBe([])
        ->and(PracticeTextNormalizer::tokens('!!!'))->toBe([]);
});

it('makes the key of an English word: case, NFKC, apostrophes and spaces - but not the punctuation', function (): void {
    expect(PracticeTextNormalizer::key('  Ice-Cream '))->toBe('ice-cream')
        ->and(PracticeTextNormalizer::key("O\u{2019}Clock"))->toBe("o'clock")
        ->and(PracticeTextNormalizer::key('Mr.   Smith'))->toBe('mr. smith')
        ->and(PracticeTextNormalizer::key("\u{FF28}ello"))->toBe('hello');
});
