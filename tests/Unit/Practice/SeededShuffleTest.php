<?php

declare(strict_types=1);

use App\Domain\Practice\Support\SeededShuffle;

/*
 * docs/16 §4.6: the keyed sort of the indices by HMAC-SHA256("{label}|{i}", seed) - the way ExamQuestionPersonalizer does it.
 */

/** The algorithm of ExamQuestionPersonalizer::permutation, written out independently. */
function referencePermutation(string $seed, string $label, int $n): array
{
    $keys = [];
    for ($i = 0; $i < $n; $i++) {
        $keys[$i] = hash_hmac('sha256', "{$label}|{$i}", $seed, true);
    }
    $order = range(0, $n - 1);
    usort($order, static fn (int $a, int $b): int => ($keys[$a] <=> $keys[$b]) ?: ($a <=> $b));

    return $order;
}

it('gives the same order for the same seed and label, every time', function (): void {
    $seed = str_repeat('a', 32);

    expect(SeededShuffle::order($seed, 'x', 8))->toBe(SeededShuffle::order($seed, 'x', 8))
        ->and(SeededShuffle::order($seed, 'x', 5))->toBe([0, 1, 3, 2, 4]); // pinned: a change of the algorithm changes every layout
});

it('is a bijection: every index once', function (int $n): void {
    $order = SeededShuffle::order(random_bytes(32), 'fragments', $n);

    expect($order)->toHaveCount($n);
    $sorted = $order;
    sort($sorted);
    expect($sorted)->toBe($n === 0 ? [] : range(0, $n - 1));
})->with([0, 1, 2, 5, 8, 20, 100]);

it('gives independent orders for different labels and different seeds', function (): void {
    $seed = str_repeat('a', 32);

    expect(SeededShuffle::order($seed, 'left', 12))->not->toBe(SeededShuffle::order($seed, 'right', 12))
        ->and(SeededShuffle::order($seed, 'left', 12))->not->toBe(SeededShuffle::order(str_repeat('b', 32), 'left', 12));
});

it('matches the permutation of the exam questions for the same key', function (): void {
    foreach ([1, 2, 5, 9, 33] as $n) {
        $seed = random_bytes(32);

        expect(SeededShuffle::order($seed, 'q', $n))->toBe(referencePermutation($seed, 'q', $n));
    }
});

it('has no order for nothing', function (): void {
    expect(SeededShuffle::order('seed', 'x', 0))->toBe([])
        ->and(SeededShuffle::order('seed', 'x', -3))->toBe([])
        ->and(SeededShuffle::apply([], 'seed', 'x'))->toBe([]);
});

it('applies the order to a list without losing or doubling an item', function (): void {
    $items = ['a', 'b', 'c', 'd', 'e', 'f'];
    $shuffled = SeededShuffle::apply($items, str_repeat('c', 32), 'opts');

    expect($shuffled)->toHaveCount(6)
        ->and(array_diff($items, $shuffled))->toBe([])
        ->and($shuffled)->toBe(SeededShuffle::apply($items, str_repeat('c', 32), 'opts'))
        ->and(array_is_list($shuffled))->toBeTrue();
});

it('makes a fresh 32-byte seed every time', function (): void {
    expect(strlen(SeededShuffle::newSeed()))->toBe(32)
        ->and(SeededShuffle::newSeed())->not->toBe(SeededShuffle::newSeed());
});
