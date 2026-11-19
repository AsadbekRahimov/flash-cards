<?php

declare(strict_types=1);

use App\Domain\Analytics\Support\ExperimentBucket;

/*
 * The sticky arm of an experiment without a column (audit 2026-11-18, the reminder question vs the plain text):
 * `crc32("salt:id") % 100 < share`.
 */

it('puts the same id into the same bucket every time', function (): void {
    foreach ([1, 42, 777001, '9000000001'] as $id) {
        $bucket = ExperimentBucket::of('reminder-quiz-2026-11', $id);

        expect($bucket)->toBeGreaterThanOrEqual(0)->toBeLessThan(100)
            ->and(ExperimentBucket::of('reminder-quiz-2026-11', $id))->toBe($bucket)
            ->and(ExperimentBucket::in('reminder-quiz-2026-11', $id, 50))->toBe($bucket < 50);
    }

    expect(ExperimentBucket::of('s', 7))->toBe(crc32('s:7') % 100);
});

it('treats 0 as nobody and 100 as everybody', function (): void {
    foreach (range(1, 500) as $id) {
        expect(ExperimentBucket::in('salt', $id, 0))->toBeFalse()
            ->and(ExperimentBucket::in('salt', $id, 100))->toBeTrue();
    }
});

it('splits many ids close to the share', function (int $share): void {
    $n = 20000;
    $in = count(array_filter(range(1, $n), static fn (int $id): bool => ExperimentBucket::in('reminder-quiz-2026-11', $id, $share)));

    // ±1.5 points: about five standard deviations of a fair split of 20 000 ids.
    expect(abs($in / $n * 100 - $share))->toBeLessThan(1.5);
})->with([10, 50, 80]);

it('only adds ids to the treated arm when the share grows', function (): void {
    foreach (range(1, 2000) as $id) {
        if (ExperimentBucket::in('salt', $id, 30)) {
            expect(ExperimentBucket::in('salt', $id, 50))->toBeTrue();
        }
    }
});

it('gives independent splits for different salts', function (): void {
    $n = 20000;
    $both = 0;
    foreach (range(1, $n) as $id) {
        $both += (int) (ExperimentBucket::in('reminder-quiz-2026-11', $id, 50) && ExperimentBucket::in('reminder-quiz-2026-12', $id, 50));
    }

    // Independent halves overlap on a quarter of the ids; the same salt would overlap on a half.
    expect(abs($both / $n - 0.25))->toBeLessThan(0.015);
});
