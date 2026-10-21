<?php

declare(strict_types=1);

use App\Domain\Learning\Services\GapFillBuilder;
use App\Models\Word;

/*
 * docs/13 §2.5: the word or one of its regular forms is masked in the example as a whole word,
 * case-insensitively; not found → null.
 */

function gapWord(string $word, ?string $example): Word
{
    return new Word(['word' => $word, 'example' => $example]);
}

beforeEach(function (): void {
    $this->builder = new GapFillBuilder;
});

it('masks the base word', function (): void {
    $gap = $this->builder->build(gapWord('resilient', 'She is a resilient girl.'));
    expect($gap?->masked)->toBe('She is a ____ girl.')
        ->and($gap?->expected)->toBe('resilient');
});

it('masks regular forms: s / es / ed / d / ing / y→ies / y→ied / e→ing', function (string $word, string $example, string $expected): void {
    $gap = $this->builder->build(gapWord($word, $example));
    expect($gap?->expected)->toBe($expected)
        ->and($gap?->masked)->toBe(str_replace($expected, '____', $example));
})->with([
    ['cat', 'Two cats sat.', 'cats'],
    ['watch', 'He watches TV.', 'watches'],
    ['walk', 'They walked home.', 'walked'],
    ['bake', 'She baked a cake.', 'baked'],
    ['walk', 'I am walking.', 'walking'],
    ['carry', 'He carries the box.', 'carries'],
    ['carry', 'He carried the box.', 'carried'],
    ['make', 'We are making tea.', 'making'],
]);

it('ignores case but keeps the form as written in the example', function (): void {
    $gap = $this->builder->build(gapWord('resilient', 'Resilient people recover.'));
    expect($gap?->masked)->toBe('____ people recover.')
        ->and($gap?->expected)->toBe('Resilient');
});

it('masks whole words only, never a substring', function (): void {
    expect($this->builder->build(gapWord('cat', 'The category is wide.')))->toBeNull()
        ->and($this->builder->build(gapWord('art', 'He is smart.')))->toBeNull();
});

it('masks every occurrence; the first one is what has to be typed', function (): void {
    $gap = $this->builder->build(gapWord('run', 'Run, run fast!'));
    expect($gap?->masked)->toBe('____, ____ fast!')->and($gap?->expected)->toBe('Run');
});

it('handles multi-word entries and apostrophes', function (): void {
    expect($this->builder->build(gapWord('give up', "Don't give up now."))?->masked)->toBe("Don't ____ now.")
        ->and($this->builder->build(gapWord("o'clock", "It is five o'clock."))?->masked)->toBe('It is five ____.');
});

it('returns null without an example or when the word is not in it', function (): void {
    expect($this->builder->build(gapWord('resilient', null)))->toBeNull()
        ->and($this->builder->build(gapWord('resilient', '   ')))->toBeNull()
        ->and($this->builder->build(gapWord('go', 'She went home.')))->toBeNull()
        ->and($this->builder->build(gapWord('', 'Anything.')))->toBeNull();
});

it('is deterministic', function (): void {
    $word = gapWord('walk', 'They walked and walked.');
    expect($this->builder->build($word))->toEqual($this->builder->build($word));
});
