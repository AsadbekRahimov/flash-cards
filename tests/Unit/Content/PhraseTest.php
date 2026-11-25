<?php

declare(strict_types=1);

/*
 * The key word of a phrase (docs/35 §3.3 b, §3.4): one word a-z, a whole word of the phrase (case-insensitive, an apostrophe belongs to
 * the word, a hyphen or a space ends it), exactly once, never the whole phrase; the mask replaces that one occurrence as it stands.
 */

use App\Domain\Content\Support\Phrase;
use App\Domain\Learning\Services\GapFillBuilder;
use App\Models\Word;

it('counts the whole-word occurrences of the gap', function (string $phrase, string $gap, int $count): void {
    expect(Phrase::occurrences($phrase, $gap))->toBe($count);
})->with([
    'once' => ['easy come, easy go', 'go', 1],
    'twice' => ['easy come, easy go', 'easy', 2],
    'case-insensitive' => ['By the way', 'by', 1],
    'not a part of a word' => ['easy come, easy go', 'com', 0],
    'absent' => ['break the ice', 'water', 0],
    'apostrophe is part of the word' => ["How's it going?", 'how', 0],
    'typographic apostrophe too' => ['How’s it going?', 'how', 0],
    'hyphen ends a word' => ['a well-known face', 'known', 1],
    'not a word a-z' => ["don't worry", "don't", 0],
]);

it('tells the gap that is the whole phrase', function (string $phrase, string $gap, bool $whole): void {
    expect(Phrase::isWholePhrase($phrase, $gap))->toBe($whole);
})->with([
    'whole' => ['Cheers!', 'cheers', true],
    'whole with spaces' => ['  cheers  ', 'Cheers', true],
    'part' => ['no worries', 'worries', false],
]);

it('masks the one occurrence as it stands and refuses an ambiguous or whole gap', function (): void {
    expect(Phrase::mask('Easy come, easy go', 'go', '____'))->toBe(['Easy come, easy ____', 'go'])
        ->and(Phrase::mask('By the way', 'by', '____'))->toBe(['____ the way', 'By'])
        ->and(Phrase::mask('easy come, easy go', 'easy', '____'))->toBeNull()
        ->and(Phrase::mask('Cheers!', 'cheers', '____'))->toBeNull()
        ->and(Phrase::mask('break the ice', 'water', '____'))->toBeNull()
        ->and(Phrase::mask('break the ice', 'i ce', '____'))->toBeNull();
});

it('builds the gap of a phrase card from the phrase itself, never from its example', function (): void {
    $phrase = new Word(['word' => 'easy come, easy go', 'part_of_speech' => 'phrase', 'example' => 'Oh well, easy come, easy go.', 'meta' => ['phrase' => ['gap' => 'go']]]);
    $broken = new Word(['word' => 'easy come, easy go', 'part_of_speech' => 'phrase', 'example' => 'go go go', 'meta' => ['phrase' => ['gap' => 'went']]]);
    $noBlock = new Word(['word' => 'easy come, easy go', 'part_of_speech' => 'phrase', 'example' => 'go', 'meta' => []]);
    $word = new Word(['word' => 'go', 'part_of_speech' => 'verb', 'example' => 'We go home.', 'meta' => ['phrase' => ['gap' => 'go']]]);

    $gap = (new GapFillBuilder)->forCard($phrase, 'a rotated example with go in it');
    expect($gap?->masked)->toBe('easy come, easy ____')
        ->and($gap?->expected)->toBe('go')
        ->and((new GapFillBuilder)->forCard($broken))->toBeNull()
        ->and((new GapFillBuilder)->forCard($noBlock))->toBeNull()
        // A word is masked in its example, as before; a stray block on a word means nothing.
        ->and((new GapFillBuilder)->forCard($word)?->masked)->toBe('We ____ home.');
});

it('gives the card block of a phrase with the literal in the card language, and nothing for a word', function (): void {
    $phrase = new Word(['word' => 'a piece of cake', 'part_of_speech' => 'phrase', 'meta' => ['phrase' => [
        'kind' => 'idiom', 'register' => 'informal', 'gap' => 'cake', 'literal' => ['ru' => 'кусок торта', 'uz' => 'bir bo\'lak tort'],
    ]]]);

    expect(Phrase::forCard($phrase, 'uz', true))->toBe(['kind' => 'idiom', 'register' => 'informal', 'variant' => 'both', 'literal' => "bir bo'lak tort"])
        ->and(Phrase::forCard($phrase, 'en', true)['literal'] ?? null)->toBe('кусок торта')
        ->and(Phrase::forCard($phrase, 'ru', false))->toBe(['kind' => 'idiom', 'register' => 'informal', 'variant' => 'both', 'literal' => null])
        ->and(Phrase::forCard(new Word(['word' => 'cake', 'part_of_speech' => 'noun']), 'ru', true))->toBeNull()
        ->and(Phrase::sectionOf('phrases'))->toBe('phrases')
        ->and(Phrase::sectionOf('general'))->toBe('courses')
        ->and(Phrase::sectionOf(null))->toBe('courses');
});
