<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\WilsonInterval;

/*
 * The Wilson 95 % interval of the retention report (audit 2026-11-18): the reference values are the textbook ones
 * (5 of 10 -> 23.7-76.3 %, 0 of 10 -> 0-27.8 %), the interval never leaves 0-100 % and is shown only under 200 learners.
 */

it('matches the reference values of the Wilson score interval', function (int $count, int $of, float $low, float $high): void {
    expect(WilsonInterval::of($count, $of))->toBe(['low' => $low, 'high' => $high]);
})->with([
    'half of ten' => [5, 10, 23.7, 76.3],
    'none of ten' => [0, 10, 0.0, 27.8],
    'all of ten' => [10, 10, 72.2, 100.0],
    'one of one' => [1, 1, 20.7, 100.0],
    '30 of 100' => [30, 100, 21.9, 39.6],
    '14 of 1000' => [14, 1000, 0.8, 2.3],
]);

it('narrows as the base grows at the same share', function (): void {
    $small = WilsonInterval::of(5, 20);
    $large = WilsonInterval::of(50, 200);

    expect($small)->not->toBeNull()->and($large)->not->toBeNull();
    expect($large['high'] - $large['low'])->toBeLessThan($small['high'] - $small['low']);
});

it('has no interval for an empty base or numbers that make no sense', function (int $count, int $of): void {
    expect(WilsonInterval::of($count, $of))->toBeNull();
})->with([
    'empty base' => [0, 0],
    'negative base' => [0, -3],
    'negative count' => [-1, 10],
    'more hits than the base' => [11, 10],
]);

it('is shown only under the small-base threshold', function (): void {
    expect(WilsonInterval::SMALL_BASE)->toBe(200)
        ->and(WilsonInterval::forSmallBase(50, 199))->toBe(WilsonInterval::of(50, 199))
        ->and(WilsonInterval::forSmallBase(50, 200))->toBeNull()
        ->and(WilsonInterval::forSmallBase(0, 0))->toBeNull();
});
