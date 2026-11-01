<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

/**
 * The layout of the rounds of "Find the pairs" (docs/16 §4.3, §4.4): how many words each round takes, and which words.
 * Pure: it knows nothing of words and translations - a word is its index and the list of the keys by which it clashes
 * with another ({@see TranslationVariants::clashKeys()}).
 *
 * HOW MANY (solo, `capacities()`): `k = min(3, floor(N / 3))` rounds out of `N` fit words; `total = min(N, 4k)` words are shared
 * out equally, the first `total mod k` rounds get one more - always 3 or 4. 12 -> 4,4,4; 11 -> 4,4,3; 10 -> 4,3,3; 9 -> 3,3,3;
 * 8 -> 4,4; 7 -> 4,3; 6 -> 3,3; 5 -> 4; 4 -> 4; 3 -> 3; 2 -> nothing. (A battle has no formula: 5 rounds of 4.)
 *
 * WHICH (`pack()`): the words go in the order of the seed (`SeededShuffle`); each takes the first round that has a free place
 * and none of the keys of the word, and a word that fits nowhere waits in the pool. So two words that clash stand in different
 * rounds and are not thrown out. A round that ends with fewer words than `$minValid` is dropped (its words go to the pool).
 *
 * One pass of this kind may leave a place empty although another order would have filled it (the last word clashes with
 * the only round that has room). The pass of the seed is the one the document describes and it is taken whenever it fills every
 * place; if not, a few more orders of the same seed are tried and the fullest layout wins - the same input and seed give the
 * same layout, always. Better layouts matter most to a battle, which needs EVERY round full or the format is refused.
 */
final class PairsPacker
{
    /** Orders tried at most; the first one is enough unless it leaves a place empty. */
    public const ATTEMPTS = 8;

    /**
     * Places of the rounds of a solo session for `$words` fit words.
     *
     * @return list<int> empty when there are too few words for even one round
     */
    public static function capacities(int $words, int $maxRounds, int $perRound, int $minPerRound): array
    {
        $rounds = min($maxRounds, intdiv(max(0, $words), max(1, $minPerRound)));
        if ($rounds < 1) {
            return [];
        }

        $total = min($words, $perRound * $rounds);
        $base = intdiv($total, $rounds);
        $extra = $total % $rounds;

        $capacities = [];
        for ($round = 0; $round < $rounds; $round++) {
            $capacities[] = $base + ($round < $extra ? 1 : 0);
        }

        return $capacities;
    }

    /**
     * Puts the words into the rounds.
     *
     * - rounds: the rounds that count, in order, each a list of word indices in the order they were placed;
     * - pool: the words that are in no round that counts, in the order of the seed;
     * - placed: words in the rounds that count; fitted: words in all rounds, those dropped too; largest: the biggest round, dropped or not;
     * - full: every place of every round is taken.
     *
     * @param  list<list<string>>  $keys  the clash keys of each word, by the index of the word
     * @param  list<int>  $capacities  the places of each round
     * @param  int  $minValid  the fewest words a round may end with and still count (a battle: all of its places)
     * @return array{rounds: list<list<int>>, pool: list<int>, placed: int, fitted: int, largest: int, full: bool}
     */
    public static function pack(array $keys, array $capacities, int $minValid, string $seed): array
    {
        $count = count($keys);
        $best = null;

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $order = SeededShuffle::order($seed, "pairs:pack:{$attempt}", $count);
            $layout = self::layout(self::pass($keys, $capacities, $order), $order, $capacities, max(1, $minValid));

            if ($best === null || [$layout['placed'], $layout['fitted']] > [$best['placed'], $best['fitted']]) {
                $best = $layout;
            }
            if ($layout['full']) {
                break;
            }
        }

        /** @var array{rounds: list<list<int>>, pool: list<int>, placed: int, fitted: int, largest: int, full: bool} */
        return $best;
    }

    /**
     * One pass: the words in `$order`, each into the first round with a free place and no clash.
     *
     * @param  list<list<string>>  $keys
     * @param  list<int>  $capacities
     * @param  list<int>  $order
     * @return list<list<int>> the words of each round
     */
    private static function pass(array $keys, array $capacities, array $order): array
    {
        $rounds = array_fill(0, count($capacities), []);
        /** @var list<array<string, true>> $taken the keys already in each round */
        $taken = array_fill(0, count($capacities), []);
        $open = array_sum($capacities);

        foreach ($order as $index) {
            if ($open === 0) {
                break;
            }

            foreach ($capacities as $round => $capacity) {
                if (count($rounds[$round]) >= $capacity || self::clashes($taken[$round], $keys[$index])) {
                    continue;
                }

                $rounds[$round][] = $index;
                foreach ($keys[$index] as $key) {
                    $taken[$round][$key] = true;
                }
                $open--;

                break;
            }
        }

        return $rounds;
    }

    /**
     * @param  array<string, true>  $taken
     * @param  list<string>  $keys
     */
    private static function clashes(array $taken, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($taken[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a pass came to.
     *
     * @param  list<list<int>>  $rounds
     * @param  list<int>  $order
     * @param  list<int>  $capacities
     * @return array{rounds: list<list<int>>, pool: list<int>, placed: int, fitted: int, largest: int, full: bool}
     */
    private static function layout(array $rounds, array $order, array $capacities, int $minValid): array
    {
        $counting = array_values(array_filter($rounds, static fn (array $round): bool => count($round) >= $minValid));
        $fitted = array_sum(array_map('count', $rounds));
        $placed = array_sum(array_map('count', $counting));
        $used = $counting === [] ? [] : array_merge(...$counting);

        return [
            'rounds' => $counting,
            'pool' => array_values(array_diff($order, $used)),
            'placed' => $placed,
            'fitted' => $fitted,
            'largest' => $rounds === [] ? 0 : max(array_map('count', $rounds)),
            'full' => $rounds !== [] && $fitted === array_sum($capacities) && count($counting) === count($rounds),
        ];
    }
}
