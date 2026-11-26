<?php

declare(strict_types=1);

use App\Domain\Cup\DTO\DayScore;
use App\Domain\Cup\Support\CupScoring;

/*
 * docs/33 §5.5 (K4): the arithmetic of a cup - the best 5 days of 7, the caps, the tie-break by round points, shared places.
 */

it('sums the best five days of seven', function (): void {
    $days = [new DayScore(1, 10), new DayScore(2, 20), new DayScore(3, 4), new DayScore(4, 18), new DayScore(5, 0), new DayScore(6, 12), new DayScore(7, 16)];

    $best = CupScoring::best($days, 5);

    expect($best->total)->toBe(20 + 18 + 16 + 12 + 10)
        ->and($best->roundPoints)->toBe(76)
        ->and($best->countedDays)->toBe([1, 2, 4, 6, 7]);
});

it('caps a day at 20 round + 5 duel points whatever the rows say, so a cup never passes 125', function (): void {
    $days = [];
    for ($d = 1; $d <= 7; $d++) {
        $days[] = new DayScore($d, 40, 9);
    }

    $best = CupScoring::best($days, 5);

    expect($best->total)->toBe(125)->and($best->roundPoints)->toBe(100);
});

it('counts, among days of equal sums, the ones richer in rounds - which is the tie-break', function (): void {
    // day 1: 15 + 5 = 20; day 2: 20 + 0 = 20 - with one counted day the round-rich day 2 counts
    $best = CupScoring::best([new DayScore(1, 15, 5), new DayScore(2, 20, 0)], 1);

    expect($best->total)->toBe(20)->and($best->roundPoints)->toBe(20)->and($best->countedDays)->toBe([2]);
});

it('takes the earlier day among fully equal days, so the choice is deterministic', function (): void {
    $best = CupScoring::best([new DayScore(3, 10), new DayScore(1, 10), new DayScore(2, 10)], 2);

    expect($best->countedDays)->toBe([1, 2]);
});

it('gives a late participant the best of the days he had: three days count three', function (): void {
    $best = CupScoring::best([new DayScore(5, 14), new DayScore(6, 20), new DayScore(7, 8)], 5);

    expect($best->total)->toBe(42)->and($best->countedDays)->toBe([5, 6, 7]);
});

it('treats no days as zero', function (): void {
    $best = CupScoring::best([], 5);

    expect($best->total)->toBe(0)->and($best->roundPoints)->toBe(0)->and($best->countedDays)->toBe([]);
});

it('never counts negative points', function (): void {
    expect(CupScoring::best([new DayScore(1, -6, -1)], 5)->total)->toBe(0);
});

it('ranks by total, then by round points, and shares a full tie: 1, 1, 3', function (): void {
    $ranks = CupScoring::ranks([
        10 => ['total' => 58, 'round' => 50],
        11 => ['total' => 58, 'round' => 50],
        12 => ['total' => 54, 'round' => 54],
        13 => ['total' => 58, 'round' => 52],
        14 => ['total' => 0, 'round' => 0],
    ]);

    expect($ranks)->toBe([13 => 1, 10 => 2, 11 => 2, 12 => 4, 14 => 5]);
});

it('gives everybody place 1 when nobody scored', function (): void {
    expect(CupScoring::ranks([3 => ['total' => 0, 'round' => 0], 1 => ['total' => 0, 'round' => 0]]))->toBe([1 => 1, 3 => 1]);
});
