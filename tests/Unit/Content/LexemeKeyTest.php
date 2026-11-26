<?php

declare(strict_types=1);

/*
 * The normalisation of the catalog key (docs/32 §2.1, S1): the table of cases. One spelling - one key; "to be" and "be" stay
 * apart; an article or the final punctuation is dropped only when something is left; a blank word has no key.
 */

use App\Domain\Content\Support\LexemeKey;

it('normalises a spelling into the catalog key', function (string $word, ?string $key): void {
    expect(LexemeKey::of($word))->toBe($key);
})->with([
    'plain' => ['apple', 'apple'],
    'case' => ['Apple', 'apple'],
    'upper case' => ['NEW YORK', 'new york'],
    'trimmed' => ['  apple  ', 'apple'],
    'inner spaces collapsed' => ["look   \t after", 'look after'],
    'no-break space' => ["ice\u{00A0}cream", 'ice cream'],
    'typographic apostrophe' => ['don’t', "don't"],
    'left quote as apostrophe' => ['rock ‘n’ roll', "rock 'n' roll"],
    'modifier apostrophe' => ['oʼclock', "o'clock"],
    'en dash' => ['well–known', 'well-known'],
    'em dash' => ['self—esteem', 'self-esteem'],
    'non-breaking hyphen' => ['e‑mail', 'e-mail'],
    'final dot' => ['etc.', 'etc'],
    'final question mark' => ['How are you?', 'how are you'],
    'final punctuation run' => ['Wow!!!', 'wow'],
    'trailing colon and space' => ['note: ', 'note'],
    'inner punctuation kept' => ['Mr. Smith', 'mr. smith'],
    'article a' => ['a dog', 'dog'],
    'article an' => ['An apple', 'apple'],
    'article the' => ['the  Internet', 'internet'],
    'article only' => ['the', 'the'],
    'article-like start of a word' => ['theory', 'theory'],
    'a as a word' => ['a', 'a'],
    'to before a verb kept' => ['to be', 'to be'],
    'be' => ['be', 'be'],
    'punctuation only' => ['?!', '?!'],
    'cyrillic lower-cased' => ['Привет', 'привет'],
    'blank' => ['   ', null],
    'empty' => ['', null],
    // Phrases (docs/35 §3.3 f): the same rules, no case of their own.
    'phrase: comma inside kept' => ['easy come, easy go', 'easy come, easy go'],
    'phrase: final question mark dropped' => ["How's it going?", "how's it going"],
    'phrase: final exclamation dropped' => ['Break a leg!', 'break a leg'],
    'phrase: final ellipsis dropped' => ['Well...', 'well'],
    'phrase: typographic apostrophe' => ['How’s it going?', "how's it going"],
    'phrase: case' => ['By The Way', 'by the way'],
    'phrase: leading article dropped' => ['A piece of cake', 'piece of cake'],
    'phrase: article inside kept' => ['break the ice', 'break the ice'],
    'phrase: leading to kept' => ['to break the ice', 'to break the ice'],
    'phrase: inner question mark kept' => ['Really? No way!', 'really? no way'],
    'phrase: UK spelling' => ['in my humble opinion, colour me surprised', 'in my humble opinion, colour me surprised'],
    'phrase: dash between replies' => ['easy come — easy go', 'easy come - easy go'],
]);

it('folds the copies of one phrase together and never glues a phrase to a single word', function (): void {
    expect(LexemeKey::of('A piece of cake'))->toBe(LexemeKey::of('piece of cake'))
        ->and(LexemeKey::of('How’s it going?'))->toBe(LexemeKey::of("how's it going"))
        ->and(LexemeKey::of('to give up'))->not->toBe(LexemeKey::of('give up'))
        // UK / US spellings are two keys: no spelling table, the error towards "different" (docs/32 §2.1).
        ->and(LexemeKey::of('a change of colour'))->not->toBe(LexemeKey::of('a change of color'))
        // A phrase and the words it is made of: never one key (the gap rule of the import keeps a phrase at two words at least).
        ->and(LexemeKey::of('piece of cake'))->not->toBe(LexemeKey::of('cake'))
        ->and(LexemeKey::of('break the ice'))->not->toBe(LexemeKey::of('ice'))
        ->and(LexemeKey::of('give up'))->not->toBe(LexemeKey::of('give'));
});

it('keeps "to be" and "be" apart and folds the copies of one spelling together', function (): void {
    expect(LexemeKey::of('to be'))->not->toBe(LexemeKey::of('be'))
        ->and(LexemeKey::of('The Apple.'))->toBe(LexemeKey::of('apple'))
        ->and(LexemeKey::of('Don’t'))->toBe(LexemeKey::of("don't"));
});

it('fits the key into the column', function (): void {
    expect(mb_strlen((string) LexemeKey::of(str_repeat('ab', 80))))->toBe(LexemeKey::MAX_LENGTH);
});
