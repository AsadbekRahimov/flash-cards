<?php

declare(strict_types=1);

/*
 * DeckImportParser (task 2D.1, docs/04 A.10): the "word — перевод" list and the CSV, cleaning,
 * dedup, junk filtering and the limits. Pure text in, rows and rejected lines out.
 */

use App\Domain\Content\DTO\DeckParseResult;
use App\Domain\Content\Services\DeckImportParser;

function deckParse(string $text, string $format = 'list', string $locale = 'ru'): DeckParseResult
{
    return (new DeckImportParser)->parse($text, $format, $locale);
}

/** @return list<array{string, string}> */
function deckPairs(DeckParseResult $result): array
{
    return array_map(fn ($row): array => [$row->word, $row->translation], $result->rows);
}

// --- list ------------------------------------------------------------------

it('parses every separator of the list format', function (string $line): void {
    expect(deckPairs(deckParse($line)))->toBe([['apple', 'яблоко']]);
})->with([
    'em dash' => ['apple — яблоко'],
    'en dash' => ['apple – яблоко'],
    'dash without spaces' => ['apple—яблоко'],
    'spaced hyphen' => ['apple - яблоко'],
    'semicolon' => ['apple;яблоко'],
    'semicolon with spaces' => ['apple ; яблоко'],
    'tab' => ["apple\tяблоко"],
    'equals' => ['apple = яблоко'],
    'arrow' => ['apple -> яблоко'],
    'unicode arrow' => ['apple → яблоко'],
]);

it('splits at the first separator, so a translation may contain one', function (): void {
    $result = deckParse("well-known — известный; знаменитый\nlook up;искать - находить");

    expect(deckPairs($result))->toBe([
        ['well-known', 'известный; знаменитый'],
        ['look up', 'искать - находить'],
    ]);
});

it('keeps phrases, apostrophes and the usual punctuation of a word', function (): void {
    $result = deckParse("don’t — не надо\nI’m (informal) — я есть\nlook forward to — ждать с нетерпением\nU.S.A. — США");

    expect(array_column(deckPairs($result), 0))->toBe(["don't", "I'm (informal)", 'look forward to', 'U.S.A.']);
});

it('puts the list translation into the uz field for an uz learner and leaves it empty for ru', function (): void {
    expect(deckParse('apple - olma', 'list', 'uz')->rows[0]->translationUz)->toBe('olma')
        ->and(deckParse('apple - яблоко', 'list', 'ru')->rows[0]->translationUz)->toBeNull();
});

it('skips blank lines but numbers lines as the learner sees them', function (): void {
    $result = deckParse("apple — яблоко\n\n   \nbroken\npear — груша");

    expect(deckPairs($result))->toBe([['apple', 'яблоко'], ['pear', 'груша']])
        ->and($result->rejected)->toBe([['line' => 4, 'reason' => 'empty_translation']]);
});

it('handles CRLF and old Mac line endings and a BOM', function (): void {
    $result = deckParse("\u{FEFF}apple — яблоко\r\npear — груша\rplum — слива");

    expect(array_column(deckPairs($result), 0))->toBe(['apple', 'pear', 'plum']);
});

// --- csv -------------------------------------------------------------------

it('parses csv with a header, in any column order, with quotes and an example', function (): void {
    $csv = "example,word,translation_uz,translation_ru\n\"I eat an apple, daily\",apple,olma,яблоко\n,pear,nok,груша";

    $result = deckParse($csv, 'csv');

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0]->word)->toBe('apple')
        ->and($result->rows[0]->translation)->toBe('яблоко')
        ->and($result->rows[0]->translationUz)->toBe('olma')
        ->and($result->rows[0]->example)->toBe('I eat an apple, daily')
        ->and($result->rows[1]->example)->toBeNull();
});

it('parses csv without a header by position', function (): void {
    $result = deckParse("apple,яблоко,olma,An apple\npear,груша", 'csv');

    expect($result->rows[0]->translationUz)->toBe('olma')
        ->and($result->rows[0]->example)->toBe('An apple')
        ->and($result->rows[1]->translationUz)->toBeNull();
});

it('detects the semicolon and tab delimiters of a spreadsheet export', function (string $delimiter): void {
    $csv = implode($delimiter, ['word', 'translation_ru'])."\n".implode($delimiter, ['apple', 'яблоко']);

    expect(deckPairs(deckParse($csv, 'csv')))->toBe([['apple', 'яблоко']]);
})->with([';', "\t", ',']);

it('uses the uz column as the translation when ru is missing', function (): void {
    $result = deckParse("word,translation_ru,translation_uz\napple,,olma", 'csv');

    expect($result->rows[0]->translation)->toBe('olma')
        ->and($result->rows[0]->translationUz)->toBe('olma');
});

it('does not take a data row for a header', function (): void {
    $result = deckParse("word,translation\nword,слово", 'csv');

    expect(deckPairs($result))->toBe([['word', 'слово']]);
});

it('numbers csv rejections by record', function (): void {
    $result = deckParse("word,translation_ru\napple,яблоко\n123,число\npear,", 'csv');

    expect($result->rejected)->toBe([
        ['line' => 3, 'reason' => 'junk'],
        ['line' => 4, 'reason' => 'empty_translation'],
    ]);
});

// --- cleaning --------------------------------------------------------------

it('strips tags, control characters and extra spaces', function (): void {
    $result = deckParse("<b>apple</b>   — \u{200B} <script>alert(1)</script>яблоко\u{0007}  красное ");

    expect(deckPairs($result))->toBe([['apple', 'alert(1)яблоко красное']]);
});

it('normalises to NFC', function (): void {
    $decomposed = "cafe\u{0301} — кафе";

    expect(deckParse($decomposed)->rows[0]->word)->toBe("caf\u{00E9}");
});

it('survives invalid UTF-8 instead of failing', function (): void {
    $result = deckParse("apple — яблоко\nbad\xC3\x28 — плохо\npear — груша");

    expect(array_column(deckPairs($result), 0))->toContain('apple', 'pear');
});

// --- rejection ---------------------------------------------------------------

it('rejects junk and says why', function (string $line, string $reason): void {
    $result = deckParse($line);

    expect($result->rows)->toBe([])
        ->and($result->rejected)->toBe([['line' => 1, 'reason' => $reason]]);
})->with([
    'no translation' => ['apple', 'empty_translation'],
    'empty translation' => ['apple —  ', 'empty_translation'],
    'no word' => ['— яблоко', 'junk'],
    'digits only' => ['12345 — число', 'junk'],
    'symbols' => ['!!! — ???', 'junk'],
    'emoji word' => ['😀 — смайл', 'junk'],
    'markup left in the word' => ['app{le} — яблоко', 'junk'],
    'link as the word' => ['http://evil.example — ссылка', 'junk'],
    'link in the translation' => ['apple — https://evil.example', 'junk'],
    'bare www' => ['apple — www.evil.example', 'junk'],
    'e-mail' => ['apple — me@evil.example', 'junk'],
    'translation without letters' => ['apple — 123', 'junk'],
    'word too long' => [str_repeat('a', 101).' — длинное', 'too_long'],
    'translation too long' => ['apple — '.str_repeat('я', 501), 'too_long'],
]);

it('accepts the exact length limits', function (): void {
    $result = deckParse(str_repeat('a', 100).' — '.str_repeat('я', 500));

    expect($result->rows)->toHaveCount(1)->and($result->rejected)->toBe([]);
});

it('rejects an example over 300 characters', function (): void {
    $csv = "word,translation_ru,example\napple,яблоко,".str_repeat('x', 301);

    expect(deckParse($csv, 'csv')->rejected)->toBe([['line' => 2, 'reason' => 'too_long']]);
});

it('drops a repeated word case-insensitively and keeps the first', function (): void {
    $result = deckParse("Apple — яблоко\napple — ещё яблоко\nAPPLE — третье");

    expect(deckPairs($result))->toBe([['Apple', 'яблоко']])
        ->and($result->rejected)->toBe([
            ['line' => 2, 'reason' => 'duplicate'],
            ['line' => 3, 'reason' => 'duplicate'],
        ]);
});

it('stops at the line limit and reports the rest as over_limit', function (): void {
    config(['decks.import.max_lines' => 3]);

    $result = deckParse("a — а\nb — б\nc — в\nd — г\ne — д");

    expect($result->rows)->toHaveCount(3)
        ->and($result->rejected)->toBe([
            ['line' => 4, 'reason' => 'over_limit'],
            ['line' => 5, 'reason' => 'over_limit'],
        ]);
});

it('counts rejected lines against the line limit too', function (): void {
    config(['decks.import.max_lines' => 2]);

    $result = deckParse("junk\nbad\na — а");

    expect($result->rows)->toBe([])->and($result->rejected[2])->toBe(['line' => 3, 'reason' => 'over_limit']);
});

it('defaults to 500 lines', function (): void {
    $text = implode("\n", array_map(fn (int $i): string => "word{$i}x — слово", range(1, 520)));
    // digits are allowed inside a word; this checks the cap, not the alphabet
    $result = deckParse($text);

    expect($result->rows)->toHaveCount(500)->and($result->rejected)->toHaveCount(20);
});

it('refuses a text over the byte cap and an unknown format', function (): void {
    config(['decks.import.max_kb' => 1]);

    expect(fn () => deckParse(str_repeat('a', 1025)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => deckParse('a — б', 'xml'))->toThrow(InvalidArgumentException::class);
});

it('returns nothing for an empty text', function (): void {
    $result = deckParse('');

    expect($result->rows)->toBe([])->and($result->rejected)->toBe([]);
});
