<?php

declare(strict_types=1);

use App\Domain\Learners\DTO\DayBounds;
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

// --- Per-day limit window and the day carried across a timezone change (docs/12 §5.3) ---------

it('uses the plain local day as the limit window while no day is carried', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent');
    $now = utc('2026-10-19 08:00:00');

    $window = $clock->limitWindowUtc($learner, $now);
    $day = $clock->dayBoundsUtc($learner, $now);

    expect($window->startUtc->equalTo($day->startUtc))->toBeTrue()
        ->and($window->endUtc->equalTo($day->endUtc))->toBeTrue()
        ->and($clock->dayCarry($learner))->toBeNull();
});

it('carries the old zone\'s day: start kept, end = first midnight of the new zone at or after the old day\'s end', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent');
    $now = utc('2026-10-19 08:00:00'); // Tashkent 19 Oct = [18th 19:00, 19th 19:00) UTC

    $clock->carryDayAcrossTimezoneChange($learner, $now);
    $learner->timezone = 'Pacific/Honolulu'; // UTC-10, midnights at 10:00 UTC

    expect($learner->meta[LearnerClock::DAY_CARRY_META])->toBe([
        'date' => '2026-10-19', 'timezone' => 'Asia/Tashkent', 'start' => '2026-10-18T19:00:00+00:00', 'end' => '2026-10-19T19:00:00+00:00',
    ]);

    $window = $clock->limitWindowUtc($learner, $now);
    expect($window->date)->toBe('2026-10-19')
        ->and($window->timezone)->toBe('Asia/Tashkent')
        ->and($window->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($window->endUtc->toDateTimeString())->toBe('2026-10-20 10:00:00');

    // Honolulu's own midnight at 19th 10:00 UTC does not open a new window; the carry does end at 20th 10:00.
    expect($clock->limitWindowUtc($learner, utc('2026-10-19 10:00:00'))->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($clock->limitWindowUtc($learner, utc('2026-10-20 09:59:59'))->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($clock->limitWindowUtc($learner, utc('2026-10-20 10:00:00'))->startUtc->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($clock->limitWindowUtc($learner, utc('2026-10-20 10:00:00'))->endUtc->toDateTimeString())->toBe('2026-10-21 10:00:00');
});

it('ends the carried day with the old one when the new zone shares its midnight', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent');

    $clock->carryDayAcrossTimezoneChange($learner, utc('2026-10-19 08:00:00'));
    $learner->timezone = 'Asia/Karachi'; // also UTC+5

    expect($clock->limitWindowUtc($learner, utc('2026-10-19 08:00:00'))->endUtc->toDateTimeString())->toBe('2026-10-19 19:00:00')
        ->and($clock->firstMidnightAtOrAfter($learner, utc('2026-10-19 19:00:00'))->toDateTimeString())->toBe('2026-10-19 19:00:00')
        ->and($clock->firstMidnightAtOrAfter($learner, utc('2026-10-19 19:00:01'))->toDateTimeString())->toBe('2026-10-20 19:00:00');
});

it('keeps an active carry on a further change and replaces an expired one', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent');

    $clock->carryDayAcrossTimezoneChange($learner, utc('2026-10-19 08:00:00'));
    $learner->timezone = 'Pacific/Honolulu';
    $first = $learner->meta[LearnerClock::DAY_CARRY_META];

    // Still carried (until 20th 10:00 UTC): the record stays, so the window start cannot move forward.
    $clock->carryDayAcrossTimezoneChange($learner, utc('2026-10-20 09:00:00'));
    expect($learner->meta[LearnerClock::DAY_CARRY_META])->toBe($first);
    $learner->timezone = 'Asia/Tokyo';
    expect($clock->limitWindowUtc($learner, utc('2026-10-20 09:00:00'))->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($clock->limitWindowUtc($learner, utc('2026-10-20 09:00:00'))->endUtc->toDateTimeString())->toBe('2026-10-20 15:00:00');

    // Expired: the current Tokyo day is carried instead.
    $clock->carryDayAcrossTimezoneChange($learner, utc('2026-10-21 12:00:00'));
    expect($learner->meta[LearnerClock::DAY_CARRY_META]['timezone'])->toBe('Asia/Tokyo')
        ->and($learner->meta[LearnerClock::DAY_CARRY_META]['start'])->toBe('2026-10-20T15:00:00+00:00');
});

it('carries a 25-hour fall-back day in full and respects DST in the new zone', function (): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Europe/Berlin'); // 25 Oct 2026: 03:00 CEST -> 02:00 CET

    $clock->carryDayAcrossTimezoneChange($learner, utc('2026-10-25 00:30:00'));
    $learner->timezone = 'America/New_York'; // still EDT (UTC-4) until 1 Nov: midnights at 04:00 UTC

    $window = $clock->limitWindowUtc($learner, utc('2026-10-25 00:30:00'));
    expect($window->startUtc->toDateTimeString())->toBe('2026-10-24 22:00:00')
        ->and($learner->meta[LearnerClock::DAY_CARRY_META]['end'])->toBe('2026-10-25T23:00:00+00:00')
        ->and($window->endUtc->toDateTimeString())->toBe('2026-10-26 04:00:00');
});

it('ignores a malformed carry record and overwrites it on the next change', function (mixed $garbage): void {
    $clock = new LearnerClock;
    $learner = clockLearner('Asia/Tashkent');
    $learner->meta = [LearnerClock::DAY_CARRY_META => $garbage, 'other' => 1];
    $now = utc('2026-10-19 08:00:00');

    expect($clock->dayCarry($learner))->toBeNull()
        ->and($clock->limitWindowUtc($learner, $now)->startUtc->toDateTimeString())->toBe('2026-10-18 19:00:00')
        ->and($clock->limitWindowUtc($learner, $now)->endUtc->toDateTimeString())->toBe('2026-10-19 19:00:00');

    $clock->carryDayAcrossTimezoneChange($learner, $now);
    expect($learner->meta['other'])->toBe(1)
        ->and($learner->meta[LearnerClock::DAY_CARRY_META]['start'])->toBe('2026-10-18T19:00:00+00:00');
})->with([
    'string' => ['yesterday'],
    'missing keys' => [['start' => '2026-10-18T19:00:00+00:00']],
    'end before start' => [['date' => '2026-10-19', 'timezone' => 'Asia/Tashkent', 'start' => '2026-10-19T19:00:00+00:00', 'end' => '2026-10-18T19:00:00+00:00']],
    'unparseable' => [['date' => 'x', 'timezone' => 'x', 'start' => 'not a date', 'end' => 'nor this']],
    'numbers' => [['date' => 1, 'timezone' => 2, 'start' => 3, 'end' => 4]],
]);

it('round-trips DayBounds through its array form', function (): void {
    $bounds = (new LearnerClock)->localDateBoundsUtc(clockLearner('Europe/Berlin'), '2026-03-29');

    $back = DayBounds::tryFromArray($bounds->toArray());

    expect($back)->not->toBeNull()
        ->and($back?->date)->toBe('2026-03-29')
        ->and($back?->timezone)->toBe('Europe/Berlin')
        ->and($back?->startUtc->equalTo($bounds->startUtc))->toBeTrue()
        ->and($back?->endUtc->equalTo($bounds->endUtc))->toBeTrue()
        ->and(DayBounds::tryFromArray(null))->toBeNull();
});
