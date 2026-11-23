<?php

declare(strict_types=1);

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\Tts\TtsCharacterBudget;
use App\Domain\Content\Services\Tts\TtsRequestThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

/*
 * docs/26 §9.3: the monthly character budget of the Google driver (one row per Pacific month in tts_usage_months) and the
 * pace limit that waits inside the driver.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['twa.tts.monthly_char_limit' => 100, 'twa.tts.rate_per_minute' => 150]);
    RateLimiter::clear(TtsRequestThrottle::KEY);
});

function ttsBudget(): TtsCharacterBudget
{
    return app(TtsCharacterBudget::class);
}

/** @return array{characters: int, requests: int}|null */
function ttsBudgetRow(string $month): ?array
{
    $row = DB::table('tts_usage_months')->where('month', $month)->first();

    return $row === null ? null : ['characters' => (int) $row->characters, 'requests' => (int) $row->requests];
}

it('has the table of §9.3', function (): void {
    expect(Schema::hasColumns('tts_usage_months', ['month', 'characters', 'requests', 'created_at', 'updated_at']))->toBeTrue();
});

it('counts the month in Los Angeles time, like the bills of Google', function (string $utc, string $month): void {
    $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

    expect(ttsBudget()->month())->toBe($month);
})->with([
    '1 Dec 07:59 UTC is still November in Los Angeles' => ['2026-12-01 07:59:00', '2026-11'],
    '1 Dec 08:00 UTC is December in Los Angeles' => ['2026-12-01 08:00:00', '2026-12'],
    'mid-month' => ['2026-11-15 12:00:00', '2026-11'],
]);

it('reserves up to the limit exactly and refuses one character more', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    expect(ttsBudget()->reserve(60))->toBe('2026-11')
        ->and(ttsBudget()->reserve(40))->toBe('2026-11')
        ->and(ttsBudgetRow('2026-11'))->toBe(['characters' => 100, 'requests' => 2])
        ->and(fn () => ttsBudget()->reserve(1))->toThrow(fn (SpeechSynthesisException $e) => expect($e->getMessage())->toBe('tts_monthly_limit_reached')
        ->and($e->retryable)->toBeFalse());

    expect(ttsBudgetRow('2026-11'))->toBe(['characters' => 100, 'requests' => 2]);
});

it('refuses a reservation larger than the whole limit, and everything with a limit of 0', function (int $limit, int $chars): void {
    config(['twa.tts.monthly_char_limit' => $limit]);

    expect(fn () => ttsBudget()->reserve($chars))->toThrow(SpeechSynthesisException::class, 'tts_monthly_limit_reached');
})->with([
    'over the limit' => [100, 101],
    'limit 0' => [0, 1],
    'limit 0, nothing asked' => [0, 0],
    'negative limit' => [-5, 1],
]);

it('gives a refund back to the month of the reservation, never below zero', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:59:00', 'America/Los_Angeles'));
    $month = ttsBudget()->reserve(30);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:01:00', 'America/Los_Angeles'));
    ttsBudget()->reserve(5);
    ttsBudget()->refund($month, 30);

    expect($month)->toBe('2026-11')
        ->and(ttsBudgetRow('2026-11'))->toBe(['characters' => 0, 'requests' => 0])
        ->and(ttsBudgetRow('2026-12'))->toBe(['characters' => 5, 'requests' => 1]);

    ttsBudget()->refund('2026-12', 50); // more than reserved: nothing
    ttsBudget()->refund('2026-10', 5); // no row: nothing
    expect(ttsBudgetRow('2026-12'))->toBe(['characters' => 5, 'requests' => 1])
        ->and(ttsBudgetRow('2026-10'))->toBeNull();
});

it('reports the usage of the month and whether it is exhausted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));

    expect(ttsBudget()->usage())->toBe(['month' => '2026-11', 'characters' => 0, 'limit' => 100])
        ->and(ttsBudget()->exhausted())->toBeFalse();

    ttsBudget()->reserve(99);
    expect(ttsBudget()->usage()['characters'])->toBe(99)->and(ttsBudget()->exhausted())->toBeFalse();

    ttsBudget()->reserve(1);
    expect(ttsBudget()->exhausted())->toBeTrue();

    config(['twa.tts.monthly_char_limit' => 0]);
    $this->travelTo(CarbonImmutable::parse('2027-01-20 12:00:00', 'UTC'));
    expect(ttsBudget()->exhausted())->toBeTrue();
});

it('passes at once under the pace limit', function (): void {
    Sleep::fake();

    app(TtsRequestThrottle::class)->acquire();
    app(TtsRequestThrottle::class)->acquire();

    Sleep::assertNeverSlept();
    expect(RateLimiter::attempts(TtsRequestThrottle::KEY))->toBe(2);
});

it('waits over the pace limit until a slot comes free, in pauses of at most 5 seconds', function (): void {
    config(['twa.tts.rate_per_minute' => 2]);
    Sleep::fake(syncWithCarbon: true);
    RateLimiter::hit(TtsRequestThrottle::KEY, 60);
    RateLimiter::hit(TtsRequestThrottle::KEY, 60);
    $this->travel(52)->seconds(); // 8 seconds left of the window

    app(TtsRequestThrottle::class)->acquire();

    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(3)->seconds()]);
});

it('gives up with the retryable tts_rate_limited after 30 seconds of waiting', function (): void {
    config(['twa.tts.rate_per_minute' => 1]);
    Sleep::fake();
    RateLimiter::hit(TtsRequestThrottle::KEY, 60);

    expect(fn () => app(TtsRequestThrottle::class)->acquire())
        ->toThrow(fn (SpeechSynthesisException $e) => expect($e->getMessage())->toBe('tts_rate_limited')
            ->and($e->retryable)->toBeTrue());

    Sleep::assertSleptTimes(6);
    Sleep::assertSequence(array_fill(0, 6, Sleep::for(5)->seconds()));
});
