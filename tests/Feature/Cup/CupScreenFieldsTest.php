<?php

declare(strict_types=1);

use App\Domain\Cup\Support\CupClock;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/04 A.23 (asked by the screens of K8): "my cups" tells the state of today's round of each cup (`me.today`, in the same one
 * statement of the list), and the cup and the round tell when the next round opens (`next_day_at` - the server decides the local
 * midnight, a change of the clocks included; no statement).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function screenHttp(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

it('tells in "my cups" whether today\'s round is not started, running or played', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $today = fn (): array => collect(screenHttp($a, 'GET', '/cups')->assertOk()->json('cups'))->firstWhere('id', $cup->id)['me']['today'];

    expect($today())->toBe(['state' => 'not_started', 'day' => 1]);

    $round = screenHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    expect($today())->toBe(['state' => 'active', 'day' => 1]);

    CarbonImmutable::setTestNow($now->addMinutes(30)); // the 10 minutes are over
    expect($today())->toBe(['state' => 'done', 'day' => 1]);

    // the next day: not started again; a cup not running yet: not available
    CarbonImmutable::setTestNow($now->addDay());
    expect($today())->toBe(['state' => 'not_started', 'day' => 2]);
    $later = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    CupKit::member($a);
    screenHttp($a, 'POST', "/cups/{$later->id}/join")->assertCreated();
    expect(collect(screenHttp($a, 'GET', '/cups')->json('cups'))->firstWhere('id', $later->id)['me']['today'])->toBe(['state' => 'not_available', 'day' => null])
        ->and($round['next_day_at'])->toBe(CupClock::dayStart($cup->starts_at, $cup->timezone, 2)->format('Y-m-d\TH:i:s.v\Z'));
});

it('tells when the next round opens: day 1 before the start, the next midnight of the cup, none on the last day', function (): void {
    ['cup' => $cup, 'learners' => [$a], 'start' => $now] = CupKit::running(2);
    $iso = fn (CarbonImmutable $at): string => $at->utc()->format('Y-m-d\TH:i:s.v\Z');

    expect(screenHttp($a, 'GET', "/cups/{$cup->id}")->assertOk()->json('cup.next_day_at'))->toBe($iso(CupClock::dayStart($cup->starts_at, 'Asia/Tashkent', 2)))
        ->and(CupClock::dayStart($cup->starts_at, 'Asia/Tashkent', 2)->setTimezone('Asia/Tashkent')->format('H:i'))->toBe('00:00');

    $round = screenHttp($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $answer = screenHttp($a, 'POST', "/cup-rounds/{$round['attempt_id']}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $round['current']['id'], 'answer' => ['skip' => true]])->assertOk()->json();
    expect($answer['round']['next_day_at'])->toBe($round['next_day_at']);

    CarbonImmutable::setTestNow($now->addDays(6)); // day 7 of 7
    expect(screenHttp($a, 'GET', "/cups/{$cup->id}")->json('cup.next_day_at'))->toBeNull();

    // A scheduled cup: its day 1.
    $scheduled = CupKit::scheduled(CupKit::group(), CarbonImmutable::now());
    expect(collect(screenHttp($a, 'GET', '/cups')->json('cups'))->firstWhere('id', $cup->id)['next_day_at'])->toBeNull();
    CupKit::member($a);
    screenHttp($a, 'POST', "/cups/{$scheduled->id}/join")->assertCreated();
    expect(collect(screenHttp($a, 'GET', '/cups')->json('cups'))->firstWhere('id', $scheduled->id)['next_day_at'])->toBe($iso(CarbonImmutable::instance($scheduled->starts_at)));
});

it('moves the next midnight with the clocks of a time zone that changes them', function (): void {
    // Europe/Berlin goes to summer time on 2027-03-28: the midnight after day 1 (03-28 00:00 CET) is 23:00 UTC, the next one 22:00 UTC.
    $start = CarbonImmutable::parse('2027-03-27 00:00', 'Europe/Berlin');
    expect(CupClock::nextRoundAt($start, 'Europe/Berlin', 7, $start->addHours(5))?->toIso8601ZuluString())->toBe('2027-03-27T23:00:00Z')
        ->and(CupClock::nextRoundAt($start, 'Europe/Berlin', 7, $start->addHours(30))?->toIso8601ZuluString())->toBe('2027-03-28T22:00:00Z')
        ->and(CupClock::nextRoundAt($start, 'Europe/Berlin', 7, $start->subHour())?->toIso8601ZuluString())->toBe('2027-03-26T23:00:00Z')
        ->and(CupClock::nextRoundAt($start, 'Europe/Berlin', 7, $start->addDays(6)->addHour()))->toBeNull();
});
