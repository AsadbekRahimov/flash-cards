<?php

declare(strict_types=1);

use App\Domain\Practice\Support\TranslationVariants;

/*
 * docs/16 §4.4: the variants of a translation - the keys by which two words clash in a round, the text of the card - and
 * the clash itself across the locales of the set, after the fallback to ru.
 */

it('reads one variant out of a plain translation', function (): void {
    expect(TranslationVariants::keys('мама'))->toBe(['мама'])
        ->and(TranslationVariants::label('мама'))->toBe('мама');
});

it('cuts out the explanations in round brackets, nested ones whole', function (string $translation, array $keys): void {
    expect(TranslationVariants::keys($translation))->toBe($keys);
})->with([
    'a note after the word' => ['мама (разг.)', ['мама']],
    'a note before the word' => ['(устар.) матушка', ['матушка']],
    'a note inside a list' => ['дом (здание), жильё (жилище)', ['дом', 'жилье']],
    'a bracket in a bracket' => ['a (b (c) d) e', ['a e']],
    'two notes' => ['мама (разг.) (ласк.)', ['мама']],
    'only a note: no variant at all' => ['(разг.)', []],
    'an unclosed bracket is text' => ['дом (здание', ['дом (здание']],
]);

it('splits a translation into variants by a comma, a semicolon and a slash', function (): void {
    expect(TranslationVariants::keys('мама, мамочка; матушка / мать'))->toBe(['мама', 'мамочка', 'матушка', 'мать'])
        ->and(TranslationVariants::keys('и/или'))->toBe(['и', 'или'])
        ->and(TranslationVariants::keys(' мама ,, ; / мамочка '))->toBe(['мама', 'мамочка']); // empty variants are dropped
});

it('compares in lower case and treats ё as е', function (): void {
    expect(TranslationVariants::keys('Ёж'))->toBe(['еж'])
        ->and(TranslationVariants::keys('ЁЖ, еЖ'))->toBe(['еж'])        // the same key twice is one
        ->and(TranslationVariants::keys('Мама'))->toBe(TranslationVariants::keys('мама'));
});

it('does not take the closing . ! ? … for a part of the variant', function (string $translation): void {
    expect(TranslationVariants::keys($translation))->toBe(['привет']);
})->with([
    'a full stop' => ['Привет.'],
    'an exclamation mark' => ['Привет!'],
    'a question mark and more' => ['привет?!'],
    'three dots' => ['Привет...'],
    'the ellipsis sign' => ["Привет\u{2026}"],
    'a space before the dot' => ['привет .'],
]);

it('keeps the punctuation inside a variant and tells a variant from its neighbour', function (): void {
    expect(TranslationVariants::keys('Как дела?'))->toBe(['как дела'])
        ->and(TranslationVariants::keys('так, как'))->toBe(['так', 'как'])
        ->and(TranslationVariants::keys('что-то'))->toBe(['что-то']); // a hyphen is a hyphen here, unlike in the dictation
});

it('applies NFKC: a ligature or a full-width letter is the plain one', function (): void {
    expect(TranslationVariants::keys("\u{FB01}ne"))->toBe(['fine'])
        ->and(TranslationVariants::keys("\u{FF33}\u{FF45}\u{FF45}"))->toBe(['see']);
});

it('squeezes the white space of a variant to one space', function (): void {
    expect(TranslationVariants::keys("  доброе \t  утро  "))->toBe(['доброе утро'])
        ->and(TranslationVariants::label("  доброе \t  утро  "))->toBe('доброе утро');
});

it('writes the card with the FIRST variant, as the author wrote it', function (string $translation, string $label): void {
    expect(TranslationVariants::label($translation))->toBe($label);
})->with([
    'case stays' => ['Мама, мамочка', 'Мама'],
    'ё stays' => ['Ёж', 'Ёж'],
    'the note is not on the card' => ['дом (здание), жильё', 'дом'],
    'a note first, then the word' => ['(разг.) мама', 'мама'],
    'an empty variant first' => [', мама', 'мама'],
    'the dot is the author\'s' => ['Привет!', 'Привет!'],
    'a variant that is only punctuation is no variant' => ['..., привет', 'привет'],
    'the first of several' => ['быть; находиться / существовать', 'быть'],
]);

it('has no card for a translation without a variant', function (string $translation): void {
    expect(TranslationVariants::label($translation))->toBeNull()
        ->and(TranslationVariants::keys($translation))->toBe([]);
})->with([
    'empty' => [''],
    'blank' => ['   '],
    'only separators' => [', ; /'],
    'only a note' => ['(разг.)'],
    'only dots' => ['...'],
]);

it('keeps the keys of the variants in order and without repeats', function (): void {
    expect(TranslationVariants::keys('b, a, B, c, a'))->toBe(['b', 'a', 'c']);
});

describe('the clash of two words', function (): void {
    it('is a shared variant: «Mother» and «Mum» are both «мама»', function (): void {
        $mother = TranslationVariants::clashKeys('mother', ['ru' => 'мама']);
        $mum = TranslationVariants::clashKeys('mum', ['ru' => 'мама, мамочка']);
        $father = TranslationVariants::clashKeys('father', ['ru' => 'папа']);

        expect(TranslationVariants::clash((array) $mother, (array) $mum))->toBeTrue()
            ->and(TranslationVariants::clash((array) $mother, (array) $father))->toBeFalse()
            ->and(TranslationVariants::clash((array) $mum, (array) $father))->toBeFalse();
    });

    it('sees through the case, ё and a closing mark', function (): void {
        $a = TranslationVariants::clashKeys('hedgehog', ['ru' => 'Ёж!']);
        $b = TranslationVariants::clashKeys('urchin', ['ru' => 'еж']);

        expect(TranslationVariants::clash((array) $a, (array) $b))->toBeTrue();
    });

    it('is the same English text, whatever the translations', function (): void {
        $bank = TranslationVariants::clashKeys('Bank', ['ru' => 'банк']);
        $shore = TranslationVariants::clashKeys("bank\u{00A0}", ['ru' => 'берег']);   // the same word, a no-break space after it
        $other = TranslationVariants::clashKeys('banker', ['ru' => 'банкир']);

        expect(TranslationVariants::clash((array) $bank, (array) $shore))->toBeTrue()
            ->and(TranslationVariants::clash((array) $bank, (array) $other))->toBeFalse();
    });

    it('is checked in EACH locale of the set', function (): void {
        // Different in ru, the same in uz: they clash for a battle of a ru player and a uz one, not for a ru solo session.
        $one = ['ru' => 'мама', 'uz' => 'ona'];
        $two = ['ru' => 'мамочка', 'uz' => 'ona'];

        expect(TranslationVariants::clash((array) TranslationVariants::clashKeys('mother', $one), (array) TranslationVariants::clashKeys('mum', $two)))->toBeTrue()
            ->and(TranslationVariants::clash(
                (array) TranslationVariants::clashKeys('mother', ['ru' => $one['ru']]),
                (array) TranslationVariants::clashKeys('mum', ['ru' => $two['ru']]),
            ))->toBeFalse();
    });

    it('does not take one string in two languages for a clash', function (): void {
        $a = TranslationVariants::clashKeys('word one', ['ru' => 'ona', 'uz' => 'x1']);
        $b = TranslationVariants::clashKeys('word two', ['ru' => 'y2', 'uz' => 'ona']);

        expect(TranslationVariants::clash((array) $a, (array) $b))->toBeFalse();
    });

    it('is a clash in uz when both words have no uz text and fall back to the same ru one', function (): void {
        // The set resolves the fallback before it asks: the text of `uz` of both words is the ru text.
        $a = TranslationVariants::clashKeys('mother', ['uz' => 'мама']);
        $b = TranslationVariants::clashKeys('mum', ['uz' => 'мама']);

        expect(TranslationVariants::clash((array) $a, (array) $b))->toBeTrue();
    });

    it('leaves a word out when it has no English text or no variant in some locale', function (): void {
        expect(TranslationVariants::clashKeys('', ['ru' => 'мама']))->toBeNull()
            ->and(TranslationVariants::clashKeys('   ', ['ru' => 'мама']))->toBeNull()
            ->and(TranslationVariants::clashKeys('mother', ['ru' => 'мама', 'uz' => '(ona)']))->toBeNull()
            ->and(TranslationVariants::clashKeys('mother', ['ru' => '']))->toBeNull()
            ->and(TranslationVariants::clashKeys('mother', []))->toBeNull();
    });

    it('names its keys by the locale and the English key', function (): void {
        expect(TranslationVariants::clashKeys("Don\u{2019}t", ['ru' => 'не, Нет', 'uz' => 'yo\'q']))
            ->toBe(["en|don't", 'ru|не', 'ru|нет', "uz|yo'q"]);
    });

    it('names the variants of the BASE translation `base|…` after the keys of the locales, as the author wrote them', function (): void {
        expect(TranslationVariants::clashKeys('mother', ['uz' => 'ona'], 'Мама (разг.), мамочка'))
            ->toBe(['en|mother', 'uz|ona', 'base|мама', 'base|мамочка']);
    });

    it('is a clash through the base: «mum» has no uz text and shows ru «мама», «mother» shows «ona» and has the same base', function (): void {
        $mother = (array) TranslationVariants::clashKeys('mother', ['uz' => 'ona'], 'мама');
        $mum = (array) TranslationVariants::clashKeys('mum', ['uz' => 'мама'], 'мама');   // no uz text: the ru one is shown
        $father = (array) TranslationVariants::clashKeys('father', ['uz' => 'ota'], 'папа');

        expect(TranslationVariants::clash($mother, $mum))->toBeTrue()
            ->and(TranslationVariants::clash($mum, $mother))->toBeTrue()
            ->and(TranslationVariants::clash($mother, $father))->toBeFalse()
            ->and(TranslationVariants::clash($mum, $father))->toBeFalse();

        // The hole this closes: the shown texts alone («ona», «мама») never meet.
        expect(TranslationVariants::clash(
            (array) TranslationVariants::clashKeys('mother', ['uz' => 'ona']),
            (array) TranslationVariants::clashKeys('mum', ['uz' => 'мама']),
        ))->toBeFalse();
    });

    it('sees the base through the case, ё, the brackets and the variants, like any other translation', function (): void {
        $a = (array) TranslationVariants::clashKeys('hedgehog', ['uz' => 'tipratikan'], 'Ёж (зверёк), колючка');
        $b = (array) TranslationVariants::clashKeys('urchin', ['uz' => 'dengiz tipratikani'], 'еж!');
        $c = (array) TranslationVariants::clashKeys('thorn', ['uz' => 'tikan'], 'шип');

        expect(TranslationVariants::clash($a, $b))->toBeTrue()
            ->and(TranslationVariants::clash($a, $c))->toBeFalse();
    });

    it('does not make a word unfit when its base is empty: no `base` key, and the rest as before', function (string $base): void {
        expect(TranslationVariants::clashKeys('mother', ['ru' => 'мама'], $base))->toBe(['en|mother', 'ru|мама']);
    })->with([
        'empty' => [''],
        'blank' => ['   '],
        'only a note' => ['(разг.)'],
        'only separators' => [', ; /'],
    ]);

    it('leaves a ru learner as he was: the shown text and the base say the same, so no pair is new', function (): void {
        $mother = (array) TranslationVariants::clashKeys('mother', ['ru' => 'мама'], 'мама');
        $mum = (array) TranslationVariants::clashKeys('mum', ['ru' => 'Мама, мамочка'], 'мама, мамочка');
        $father = (array) TranslationVariants::clashKeys('father', ['ru' => 'папа'], 'папа');

        expect(TranslationVariants::clash($mother, $mum))->toBeTrue()
            ->and(TranslationVariants::clash($mother, $father))->toBeFalse();
    });
});
