<?php

declare(strict_types=1);

use App\Domain\Practice\Support\PairsPacker;

/*
 * docs/16 §4.3 (how many words a round takes) and §4.4 (which words stand together): the pure layout of the rounds of the pairs.
 */

/** Words that clash with nobody: each has a key of its own. @return list<list<string>> */
function packerFree(int $count): array
{
    return array_map(fn (int $i): array => ["w{$i}"], $count < 1 ? [] : range(0, $count - 1));
}

/** @param array{rounds: list<list<int>>, pool: list<int>} $layout @return list<int> every index in the rounds that count */
function packerPlaced(array $layout): array
{
    return $layout['rounds'] === [] ? [] : array_merge(...$layout['rounds']);
}

describe('how many words a round takes', function (): void {
    it('shares N words out as the table of docs/16 §4.3', function (int $words, array $expected): void {
        expect(PairsPacker::capacities($words, 3, 4, 3))->toBe($expected);
    })->with([
        'two words are not enough' => [2, []],
        'none' => [0, []],
        'three' => [3, [3]],
        'four' => [4, [4]],
        'five: one round of four' => [5, [4]],
        'six' => [6, [3, 3]],
        'seven' => [7, [4, 3]],
        'eight' => [8, [4, 4]],
        'nine' => [9, [3, 3, 3]],
        'ten' => [10, [4, 3, 3]],
        'eleven' => [11, [4, 4, 3]],
        'twelve' => [12, [4, 4, 4]],
        'thirteen: the 13th waits' => [13, [4, 4, 4]],
        'many' => [200, [4, 4, 4]],
    ]);

    it('gives every round 3 or 4 places and never more than 3 rounds, whatever N is', function (): void {
        foreach (range(3, 120) as $words) {
            $capacities = PairsPacker::capacities($words, 3, 4, 3);

            expect(count($capacities))->toBe(min(3, intdiv($words, 3)))
                ->and(array_sum($capacities))->toBe(min($words, 4 * count($capacities)))
                ->and(min($capacities))->toBeGreaterThanOrEqual(3)
                ->and(max($capacities))->toBeLessThanOrEqual(4);
        }
    });

    it('follows the numbers it is given, not 3-4-3', function (): void {
        expect(PairsPacker::capacities(10, 5, 4, 3))->toBe([4, 3, 3])
            ->and(PairsPacker::capacities(100, 2, 5, 3))->toBe([5, 5])
            ->and(PairsPacker::capacities(8, 3, 4, 2))->toBe([3, 3, 2])
            ->and(PairsPacker::capacities(5, 0, 4, 3))->toBe([]);
    });
});

describe('which words stand together', function (): void {
    it('puts every word in one place at most, and fills the rounds up to their places', function (): void {
        $layout = PairsPacker::pack(packerFree(14), [4, 4, 4], 3, 'seed');
        $placed = packerPlaced($layout);

        expect(array_map('count', $layout['rounds']))->toBe([4, 4, 4])
            ->and($placed)->toHaveCount(12)
            ->and(array_unique($placed))->toHaveCount(12)
            ->and($layout['pool'])->toHaveCount(2)
            ->and(array_intersect($layout['pool'], $placed))->toBe([])
            ->and([$layout['placed'], $layout['fitted'], $layout['largest'], $layout['full']])->toBe([12, 12, 4, true]);
    });

    it('takes the words in the order of the seed: the same seed, the same rounds; another seed, other words', function (): void {
        $keys = packerFree(30);

        expect(PairsPacker::pack($keys, [4, 4, 4], 3, 'one'))->toBe(PairsPacker::pack($keys, [4, 4, 4], 3, 'one'))
            ->and(PairsPacker::pack($keys, [4, 4, 4], 3, 'one')['rounds'])->not->toBe(PairsPacker::pack($keys, [4, 4, 4], 3, 'two')['rounds']);
    });

    it('puts two words that clash into DIFFERENT rounds and keeps both', function (): void {
        // 0 and 1 are both "мама".
        $keys = packerFree(8);
        $keys[0] = ['ru|мама'];
        $keys[1] = ['ru|мама'];

        foreach (range(0, 59) as $n) {
            $layout = PairsPacker::pack($keys, [4, 4], 3, "seed-{$n}");

            expect($layout['full'])->toBeTrue();
            foreach ($layout['rounds'] as $round) {
                expect(in_array(0, $round, true) && in_array(1, $round, true))->toBeFalse("seed-{$n}");
            }
            expect(array_unique(packerPlaced($layout)))->toHaveCount(8); // none was thrown out
        }
    });

    it('lets a word clash through any one of several keys', function (): void {
        // 0 has two variants, each shared with another word: all three stand apart.
        $layout = PairsPacker::pack([['a', 'b'], ['a'], ['b'], ['c'], ['d'], ['e']], [2, 2, 2], 2, 'x');

        foreach ($layout['rounds'] as $round) {
            expect(in_array(0, $round, true) && (in_array(1, $round, true) || in_array(2, $round, true)))->toBeFalse();
        }
    });

    it('leaves a word that fits nowhere in the pool', function (): void {
        // Three words say the same thing and there are only two rounds: the third has no round.
        $layout = PairsPacker::pack([['x'], ['x'], ['x'], ['a'], ['b'], ['c'], ['d'], ['e']], [4, 4], 3, 'seed');

        expect($layout['full'])->toBeFalse()
            ->and($layout['placed'])->toBe(7)
            ->and($layout['pool'])->toHaveCount(1)
            ->and(array_map('count', $layout['rounds']))->toContain(4)
            ->and(array_sum(array_map('count', $layout['rounds'])))->toBe(7);
        // Whichever word of the three is left, it is one of them.
        expect([0, 1, 2])->toContain($layout['pool'][0]);
    });

    it('drops a round that ends with fewer words than it needs, and its words go to the pool', function (): void {
        // Three of four say the same: one round of 4 places can hold the "x" once and the "b".
        $layout = PairsPacker::pack([['x'], ['x'], ['x'], ['b']], [4], 3, 'seed');

        expect($layout['rounds'])->toBe([])
            ->and($layout['placed'])->toBe(0)
            ->and($layout['fitted'])->toBe(2)
            ->and($layout['largest'])->toBe(2)
            ->and($layout['full'])->toBeFalse()
            ->and($layout['pool'])->toHaveCount(4);
    });

    it('keeps the rounds that are big enough when another one is not', function (): void {
        // Places 3 + 3; five words say "x": only one of them fits per round, the rest cannot stand anywhere.
        $keys = [['x'], ['x'], ['x'], ['x'], ['x'], ['a'], ['b'], ['c']];
        $layout = PairsPacker::pack($keys, [4, 4], 3, 'seed');

        expect($layout['rounds'])->not->toBe([])
            ->and($layout['placed'])->toBe(array_sum(array_map('count', $layout['rounds'])));
        foreach ($layout['rounds'] as $round) {
            expect(count($round))->toBeGreaterThanOrEqual(3);
        }
    });

    it('is not fooled by an unlucky order: a word that clashes with all the others is found a place in a pass that suits', function (): void {
        // Word 0 clashes with each of 1, 2, 3, and those three do not clash among themselves: the only round of 3 is {1, 2, 3}.
        // A pass that takes 0 first fills the round with 0 alone (1 in 4 passes), so the packer tries other orders of the seed.
        $keys = [['a', 'b', 'c'], ['a'], ['b'], ['c']];

        foreach (range(0, 199) as $n) {
            $layout = PairsPacker::pack($keys, [4], 3, "star-{$n}");

            expect($layout['rounds'])->toHaveCount(1, "star-{$n}")
                ->and($layout['rounds'][0])->toHaveCount(3)
                ->and($layout['rounds'][0])->not->toContain(0);
        }
    });

    it('fills all 5 rounds of a battle of exactly 20 words even with a pair that clashes, whatever the seed', function (): void {
        // One pass leaves a place empty now and then (the last word meets the only round with room, the other one of the pair is in it).
        $keys = packerFree(20);
        $keys[3] = ['ru|мама'];
        $keys[11] = ['ru|мама'];

        foreach (range(0, 299) as $n) {
            $layout = PairsPacker::pack($keys, array_fill(0, 5, 4), 4, "battle-{$n}");

            expect($layout['full'])->toBeTrue("battle-{$n}")
                ->and($layout['rounds'])->toHaveCount(5)
                ->and(array_unique(packerPlaced($layout)))->toHaveCount(20);
        }
    });

    it('counts a battle round only when it is full', function (): void {
        // 20 places, 19 words that can stand together: no round is "enough" but the words that fit are counted.
        $layout = PairsPacker::pack(packerFree(19), array_fill(0, 5, 4), 4, 'seed');

        expect($layout['full'])->toBeFalse()
            ->and($layout['rounds'])->toHaveCount(4)
            ->and($layout['placed'])->toBe(16)
            ->and($layout['fitted'])->toBe(19)
            ->and($layout['pool'])->toHaveCount(3);
    });

    it('makes nothing of nothing', function (): void {
        expect(PairsPacker::pack([], [], 3, 'seed'))->toBe(['rounds' => [], 'pool' => [], 'placed' => 0, 'fitted' => 0, 'largest' => 0, 'full' => false])
            ->and(PairsPacker::pack(packerFree(5), [], 3, 'seed')['rounds'])->toBe([]);
    });
});
