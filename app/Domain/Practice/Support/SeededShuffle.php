<?php

declare(strict_types=1);

namespace App\Domain\Practice\Support;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The deterministic shuffle of the practice (docs/16 §4.6): a keyed sort of the indices by `HMAC-SHA256("{label}|{i}", seed)`,
 * the way `ExamQuestionPersonalizer::permutation` does it. A sort by a pseudo-random key is a bijection by construction
 * (a tie - practically impossible with 256-bit keys - is broken by the index).
 *
 * The `seed` is `random_bytes(32)` for a session / a battle, used when the snapshot is built and NOT stored: the layout is
 * already frozen in the snapshot, so a reload returns the same one. The tests pass a seed of their own and get the same
 * order every time. Different labels give independent permutations from one seed.
 */
final class SeededShuffle
{
    private const HMAC_ALGO = 'sha256';

    /**
     * The permutation of `0..n-1`.
     *
     * @return list<int>
     */
    public static function order(string $seed, string $label, int $n): array
    {
        if ($n <= 0) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $n; $i++) {
            $keys[$i] = hash_hmac(self::HMAC_ALGO, "{$label}|{$i}", $seed, true);
        }

        $order = range(0, $n - 1);
        usort($order, static fn (int $a, int $b): int => strcmp($keys[$a], $keys[$b]) ?: ($a <=> $b));

        return $order;
    }

    /**
     * `$k` distinct indices of `0..n-1`, ascending, chosen by the seed under the label - a SAMPLE, for when the whole permutation of
     * {@see order()} (an HMAC and a sort per index) is not needed: 100 000 indices cost milliseconds. The generator is local (a
     * Xoshiro256** seeded by `HMAC(label, seed)`), so the choice is the same for the same arguments and touches no global state.
     * Not the order of {@see order()}: the two never have to agree.
     *
     * @return list<int>
     */
    public static function sample(string $seed, string $label, int $n, int $k): array
    {
        if ($n <= 0 || $k <= 0) {
            return [];
        }
        if ($k >= $n) {
            return range(0, $n - 1);
        }

        $randomizer = new Randomizer(new Xoshiro256StarStar(hash_hmac(self::HMAC_ALGO, $label, $seed, true)));
        $picked = $randomizer->pickArrayKeys(range(0, $n - 1), $k);
        sort($picked);

        return $picked;
    }

    /**
     * The same items in the order of {@see order()}.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public static function apply(array $items, string $seed, string $label): array
    {
        $shuffled = [];
        foreach (self::order($seed, $label, count($items)) as $index) {
            $shuffled[] = $items[$index];
        }

        return $shuffled;
    }

    /** A fresh seed for a session / a battle. */
    public static function newSeed(): string
    {
        return random_bytes(32);
    }
}
