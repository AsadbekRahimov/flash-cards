<?php

declare(strict_types=1);

use App\Domain\Practice\Support\SeededShuffle;

it('samples the same indices for the same seed, label and size', function (): void {
    expect(SeededShuffle::sample('seed', 'label', 500, 30))->toBe(SeededShuffle::sample('seed', 'label', 500, 30));
});

it('samples other indices for another seed or another label', function (): void {
    $base = SeededShuffle::sample('seed', 'label', 500, 30);

    expect(SeededShuffle::sample('other', 'label', 500, 30))->not->toBe($base)
        ->and(SeededShuffle::sample('seed', 'other', 500, 30))->not->toBe($base);
});

it('samples k distinct indices of the range in ascending order', function (): void {
    $sample = SeededShuffle::sample(str_repeat('a', 32), 'l', 200, 50);

    expect($sample)->toHaveCount(50)->and(array_values(array_unique($sample)))->toBe($sample)
        ->and(min($sample))->toBeGreaterThanOrEqual(0)->and(max($sample))->toBeLessThanOrEqual(199);
    $sorted = $sample;
    sort($sorted);
    expect($sample)->toBe($sorted);
});

it('samples everything when k covers n, and nothing from nothing', function (): void {
    expect(SeededShuffle::sample('s', 'l', 5, 5))->toBe([0, 1, 2, 3, 4])
        ->and(SeededShuffle::sample('s', 'l', 5, 9))->toBe([0, 1, 2, 3, 4])
        ->and(SeededShuffle::sample('s', 'l', 0, 3))->toBe([])
        ->and(SeededShuffle::sample('s', 'l', 5, 0))->toBe([]);
});

it('samples every index about equally often over many seeds', function (): void {
    $hits = array_fill(0, 20, 0);
    for ($i = 0; $i < 2000; $i++) {
        foreach (SeededShuffle::sample("seed-{$i}", 'l', 20, 5) as $index) {
            $hits[$index]++;
        }
    }

    // 2000 x 5 / 20 = 500 expected per index.
    expect(min($hits))->toBeGreaterThan(400)->and(max($hits))->toBeLessThan(600);
});

it('samples a big range much faster than it permutes it', function (): void {
    $start = hrtime(true);
    $sample = SeededShuffle::sample('seed', 'l', 100_000, 300);
    $sampled = hrtime(true) - $start;

    $start = hrtime(true);
    SeededShuffle::order('seed', 'l', 100_000);
    $permuted = hrtime(true) - $start;

    expect($sample)->toHaveCount(300)->and($sampled)->toBeLessThan($permuted);
});
