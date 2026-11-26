<?php

declare(strict_types=1);

use App\Domain\Cup\Support\CupClock;
use Carbon\CarbonImmutable;

/*
 * docs/33 §3.1 (K4): the days of a cup are the midnights of its timezone, stored in UTC.
 */

it('starts at the next Tashkent midnight when it is at least 6 hours away', function (): void {
    // 11:00 in Tashkent (UTC+5) -> 00:00 next day = 19:00 UTC of the same day
    $start = CupClock::firstStart(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'), 'Asia/Tashkent', 6);

    expect($start->toIso8601ZuluString())->toBe('2026-12-01T19:00:00Z');
});

it('skips a midnight that is less than 6 hours away', function (): void {
    // 20:30 in Tashkent: the midnight is 3.5 h away -> the one after it
    $start = CupClock::firstStart(CarbonImmutable::parse('2026-12-01 15:30:00', 'UTC'), 'Asia/Tashkent', 6);

    expect($start->toIso8601ZuluString())->toBe('2026-12-02T19:00:00Z');
});

it('takes a midnight exactly 6 hours away', function (): void {
    $start = CupClock::firstStart(CarbonImmutable::parse('2026-12-01 13:00:00', 'UTC'), 'Asia/Tashkent', 6);

    expect($start->toIso8601ZuluString())->toBe('2026-12-01T19:00:00Z');
});

it('puts the borders of every day at the local midnight and ends 7 days later', function (): void {
    $start = CarbonImmutable::parse('2026-12-01 19:00:00', 'UTC');

    expect(CupClock::dayStart($start, 'Asia/Tashkent', 1)->toIso8601ZuluString())->toBe('2026-12-01T19:00:00Z')
        ->and(CupClock::dayStart($start, 'Asia/Tashkent', 3)->toIso8601ZuluString())->toBe('2026-12-03T19:00:00Z')
        ->and(CupClock::endOf($start, 'Asia/Tashkent', 7)->toIso8601ZuluString())->toBe('2026-12-08T19:00:00Z');
});

it('keeps a local day a calendar day across a DST change of another timezone', function (): void {
    // Berlin goes to winter time on 2026-10-25: that day lasts 25 hours
    $start = CarbonImmutable::parse('2026-10-23 22:00:00', 'UTC'); // 00:00 CEST on the 24th

    expect(CupClock::dayStart($start, 'Europe/Berlin', 2)->toIso8601ZuluString())->toBe('2026-10-24T22:00:00Z')
        ->and(CupClock::dayStart($start, 'Europe/Berlin', 3)->toIso8601ZuluString())->toBe('2026-10-25T23:00:00Z');
});

it('numbers the days by the local calendar', function (): void {
    $start = CarbonImmutable::parse('2026-12-01 19:00:00', 'UTC');

    expect(CupClock::dayNumber($start, 'Asia/Tashkent', CarbonImmutable::parse('2026-12-01 18:59:59', 'UTC')))->toBe(0)
        ->and(CupClock::dayNumber($start, 'Asia/Tashkent', CarbonImmutable::parse('2026-12-01 19:00:00', 'UTC')))->toBe(1)
        ->and(CupClock::dayNumber($start, 'Asia/Tashkent', CarbonImmutable::parse('2026-12-02 18:59:59', 'UTC')))->toBe(1)
        ->and(CupClock::dayNumber($start, 'Asia/Tashkent', CarbonImmutable::parse('2026-12-04 10:00:00', 'UTC')))->toBe(3)
        ->and(CupClock::dayNumber($start, 'Asia/Tashkent', CarbonImmutable::parse('2026-12-08 19:00:00', 'UTC')))->toBe(8);
});

it('moves a start to the next midnight', function (): void {
    expect(CupClock::nextDay(CarbonImmutable::parse('2026-12-01 19:00:00', 'UTC'), 'Asia/Tashkent')->toIso8601ZuluString())->toBe('2026-12-02T19:00:00Z');
});
