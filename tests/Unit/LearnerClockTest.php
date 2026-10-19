<?php

declare(strict_types=1);

use App\Domain\Learners\Services\LearnerClock;
use App\Models\Learner;
use Carbon\CarbonImmutable;

/*
 * Pure date arithmetic — no database. docs/12 §3.1, §5.3, §7.4, §7.5.
 */

function clockLearner(string $timezone): Learner
{
    return new Learner(['timezone' => $timezone, 'daily_goal' => 20]);
}

function utc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

it('splits reviews at 23:59 and 00:01 local Tashkent time into two different days even when UTC date is the same', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent'); // UTC+5, no DST

    // 2026-10-18 18:59 UTC = 23:59 local; 19:01 UTC = 00:01 next local day.
    expect($clock->localDate($learner, utc('2026-10-18 18:59:00')))->toBe('2026-10-18')
        ->and($clock->localDate($learner, utc('2026-10-18 19:01:00')))->toBe('2026-10-19')
        ->and($clock->localDate(clockLearner('UTC'), utc('2026-10-18 19:01:00')))->toBe('2026-10-18');
});

it('returns local midnight in the learner zone from today()', function (): void {
    $today = (new LearnerClock)->today(clockLearner('Asia/Tashkent'), utc('2026-10-18 19:01:00'));

    expect($today->toDateTimeString())->toBe('2026-10-19 00:00:00')
        ->and($today->getTimezone()->getName())->toBe('Asia/Tashkent')
        ->and($today->utc()->toDateTimeString())->toBe('2026-10-18 19:00:00');
});

it('computes UTC day bounds of the current local day for a fixed-offset zone', function (): void {
    $bounds = (new LearnerClock)->dayBoundsUtc(clockLearner('Asia/Tashkent'), utc('2026-10-18 19:01:00'));

    expect($bounds->date)->toBe('2026-10-19')
        ->and($bounds->timezone)->toBe('Asia/Tashkent')
        ->and($bounds->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($bounds->endUtc->toDateTimeString())->toBe('2026-10-19 19:00:00')
        ->and($bounds->contains(utc('2026-10-18 19:00:00')))->toBeTrue()
        ->and($bounds->contains(utc('2026-10-19 18:59:59')))->toBeTrue()
        ->and($bounds->contains(utc('2026-10-19 19:00:00')))->toBeFalse()
        ->and($bounds->contains(utc('2026-10-18 18:59:59')))->toBeFalse();
});

it('gives a 23-hour day on the Berlin spring-forward date and a 25-hour day on fall-back', function (): void {
    $clock = new LearnerClock;
    $berlin = clockLearner('Europe/Berlin');

    // 2026-03-29: 02:00 CET → 03:00 CEST.
    $spring = $clock->localDateBoundsUtc($berlin, '2026-03-29');
    expect($spring->startUtc->toDateTimeString())->toBe('2026-03-28 23:00:00')
        ->and($spring->endUtc->toDateTimeString())->toBe('2026-03-29 22:00:00')
        ->and($spring->startUtc->diffInHours($spring->endUtc))->toBe(23.0);

    // 2026-10-25: 03:00 CEST → 02:00 CET.
    $fall = $clock->localDateBoundsUtc($berlin, '2026-10-25');
    expect($fall->startUtc->toDateTimeString())->toBe('2026-10-24 22:00:00')
        ->and($fall->endUtc->toDateTimeString())->toBe('2026-10-25 23:00:00')
        ->and($fall->startUtc->diffInHours($fall->endUtc))->toBe(25.0);

    // Instants around the transition still map to the right local date.
    expect($clock->localDate($berlin, utc('2026-03-29 00:30:00')))->toBe('2026-03-29')  // 01:30 CET
        ->and($clock->localDate($berlin, utc('2026-03-29 21:59:59')))->toBe('2026-03-29') // 23:59:59 CEST
        ->and($clock->localDate($berlin, utc('2026-03-29 22:00:00')))->toBe('2026-03-30')
        ->and($clock->localDate($berlin, utc('2026-10-25 22:59:59')))->toBe('2026-10-25') // 23:59:59 CET
        ->and($clock->localDate($berlin, utc('2026-10-25 23:00:00')))->toBe('2026-10-26');
});

it('rejects a malformed or non-existent local date', function (string $date): void {
    expect(fn () => (new LearnerClock)->localDateBoundsUtc(clockLearner('UTC'), $date))
        ->toThrow(InvalidArgumentException::class);
})->with(['2026-1-5', '2026-02-30', '2026-13-01', '2026-00-10', '2026-04-31', '2026-10-19 ', '']);

it('accepts a leap day only in a leap year', function (): void {
    $clock = new LearnerClock;

    expect($clock->localDateBoundsUtc(clockLearner('UTC'), '2028-02-29')->date)->toBe('2028-02-29')
        ->and(fn () => $clock->localDateBoundsUtc(clockLearner('UTC'), '2026-02-29'))->toThrow(InvalidArgumentException::class);
});

it('falls back to the configured default zone and then UTC for an unknown timezone', function (): void {
    config()->set('learners.default_timezone', 'Asia/Tashkent');
    $clock = new LearnerClock;

    expect($clock->timezone(clockLearner('Mars/Olympus'))->getName())->toBe('Asia/Tashkent')
        ->and($clock->timezone(clockLearner(''))->getName())->toBe('Asia/Tashkent');

    config()->set('learners.default_timezone', 'Nowhere/Invalid');
    expect($clock->timezone(clockLearner('Mars/Olympus'))->getName())->toBe('UTC');
});

it('finds the next occurrence of a local reminder time strictly after the given instant', function (): void {
    $clock = new LearnerClock;
    $tashkent = clockLearner('Asia/Tashkent'); // 19:00 local = 14:00 UTC

    expect($clock->nextOccurrenceUtc($tashkent, '19:00', utc('2026-10-18 10:00:00'))->toDateTimeString())->toBe('2026-10-18 14:00:00')
        // exactly at the reminder instant → tomorrow (the dispatcher already fired this one)
        ->and($clock->nextOccurrenceUtc($tashkent, '19:00:00', utc('2026-10-18 14:00:00'))->toDateTimeString())->toBe('2026-10-19 14:00:00')
        ->and($clock->nextOccurrenceUtc($tashkent, '19:00', utc('2026-10-18 14:00:01'))->toDateTimeString())->toBe('2026-10-19 14:00:00')
        // 23:30 UTC = 04:30 local next day → that day's 19:00
        ->and($clock->nextOccurrenceUtc($tashkent, '19:00', utc('2026-10-18 23:30:00'))->toDateTimeString())->toBe('2026-10-19 14:00:00');
});

it('keeps the wall-clock reminder time across a DST transition', function (): void {
    $clock = new LearnerClock;
    $berlin = clockLearner('Europe/Berlin');

    // Saturday 2026-03-28 19:00 CET = 18:00 UTC; Sunday 2026-03-29 19:00 CEST = 17:00 UTC.
    $sat = $clock->nextOccurrenceUtc($berlin, '19:00', utc('2026-03-28 12:00:00'));
    $sun = $clock->nextOccurrenceUtc($berlin, '19:00', $sat);

    expect($sat->toDateTimeString())->toBe('2026-03-28 18:00:00')
        ->and($sun->toDateTimeString())->toBe('2026-03-29 17:00:00')
        ->and($sun->setTimezone('Europe/Berlin')->format('H:i'))->toBe('19:00');

    // A time inside the spring-forward gap (02:30 does not exist) resolves forward, never backwards.
    $gap = $clock->nextOccurrenceUtc($berlin, '02:30', utc('2026-03-28 12:00:00'));
    expect($gap->toDateTimeString())->toBe('2026-03-29 01:30:00')
        ->and($gap->isAfter(utc('2026-03-28 12:00:00')))->toBeTrue();
});

it('rejects a malformed local time', function (): void {
    $clock = new LearnerClock;

    expect(fn () => $clock->nextOccurrenceUtc(clockLearner('UTC'), '25:00', utc('2026-10-18 12:00:00')))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $clock->nextOccurrenceUtc(clockLearner('UTC'), '7pm', utc('2026-10-18 12:00:00')))
        ->toThrow(InvalidArgumentException::class);
});
