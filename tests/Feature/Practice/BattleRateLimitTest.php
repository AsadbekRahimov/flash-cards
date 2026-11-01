<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Domain\Practice\Services\Battle\BattleReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §7.5: the limiters of the battle, keyed by the learner. The common `twa-api` (60 a minute) covers the reads and the actions;
 * the invitation (`twa-practice-open` + `twa-battle-invite`, 5 a minute) and the actions (`twa-battle-action`, 60) have buckets of their
 * own on top of it. The POLL (`twa-battle-poll`, 90) and the AUDIO (`twa-practice-audio`, 60) are OUTSIDE `twa-api`: a poll every 2 s is
 * 30 a minute and must not eat the budget of the rounds and the answers; five clips loaded at once must not either.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The throttle middleware of a named route, in order. @return list<string> */
function battleLimiters(string $route): array
{
    $found = Route::getRoutes()->getByName($route);
    if ($found === null) {
        throw new RuntimeException("No route {$route}.");
    }

    return array_values(array_filter(
        array_map('strval', $found->gatherMiddleware()),
        static fn (string $name): bool => str_starts_with($name, 'throttle:'),
    ));
}

it('puts each route of the battle behind the limiters the document names, the poll and the audio outside `twa-api`', function (): void {
    $api = ['throttle:twa-api'];
    $action = ['throttle:twa-api', 'throttle:twa-battle-action'];

    expect(battleLimiters('twa.practice.battles.options'))->toBe($api)
        ->and(battleLimiters('twa.practice.battles.index'))->toBe($api)
        ->and(battleLimiters('twa.practice.battles.show'))->toBe($api)
        ->and(battleLimiters('twa.practice.battles.round'))->toBe($api)
        ->and(battleLimiters('twa.practice.battles.store'))->toBe(['throttle:twa-api', 'throttle:twa-practice-open', 'throttle:twa-battle-invite'])
        ->and(battleLimiters('twa.practice.battles.accept'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.decline'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.cancel'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.ready'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.surrender'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.answer'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.audio-failed'))->toBe($action)
        ->and(battleLimiters('twa.practice.battles.poll'))->toBe(['throttle:twa-battle-poll'])
        ->and(battleLimiters(BattleReader::AUDIO_ROUTE))->toBe(['throttle:twa-practice-audio']);

    // The URL the reader builds is the route that is registered (the same path the TWA fetches).
    expect(route(BattleReader::AUDIO_ROUTE, ['battle' => 15, 'element' => 'eD4abcdefgh'], false))->toBe('/api/twa/practice/battles/15/audio/eD4abcdefgh');
});

it('keeps the poll out of the budget of `twa-api`: 30 polls and 60 other requests in a minute all pass, the 61st other request is 429, and the polls go on', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    PracticeKit::resetLimiters($me);

    foreach (range(1, 30) as $_) {
        BattleHttp::poll($me, $id)->assertOk();   // a minute of a screen that polls every 2 s
    }
    foreach (range(1, 60) as $_) {
        BattleHttp::view($me, $id)->assertOk();   // all 60 of `twa-api` are still there
    }
    BattleHttp::view($me, $id)->assertStatus(429)->assertHeader('Retry-After');

    // The budget of the rounds is empty; the heartbeat is not: the player is not put out of the match by their own reads.
    BattleHttp::poll($me, $id)->assertOk();
    BattleHttp::view($friend, $id)->assertOk();   // and it is per learner
});

it('stops a poll that does not stop at 90 a minute, for that learner only, and leaves the rest of the API alone', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    PracticeKit::resetLimiters($me);

    PracticeKit::exhaust('twa-battle-poll', $me, 89);
    BattleHttp::poll($me, $id)->assertOk();
    BattleHttp::poll($me, $id)->assertStatus(429)->assertHeader('Retry-After');

    BattleHttp::view($me, $id)->assertOk();
    BattleHttp::get($me, '')->assertOk();
    BattleHttp::poll($friend, $id)->assertOk();
});

it('gives the actions a bucket of 60 a minute on top of `twa-api`, shared by all of them and not by the reads', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);
    PracticeKit::resetLimiters($me);

    PracticeKit::exhaust('twa-battle-action', $me, 59);
    BattleHttp::ready($me, $id)->assertOk();
    BattleHttp::ready($me, $id)->assertStatus(429)->assertHeader('Retry-After');
    BattleHttp::cancel($me, $id)->assertStatus(429);
    BattleHttp::answer($me, $id, 1, ['skip' => true])->assertStatus(429);
    BattleHttp::audioFailed($me, $id, 'eNoSuchEl00')->assertStatus(429);

    BattleHttp::view($me, $id)->assertOk();
    BattleHttp::round($me, $id, 1)->assertStatus(409);   // the read is answered (not yet open), not throttled
    BattleHttp::accept($friend, $id)->assertOk();        // the friend's bucket is their own
});

it('allows 5 invitations a minute - the refused ones count too - and shares the 10 of `twa-practice-open` with the solo sessions', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $body = ['friendship_id' => $friendship->id, 'mode' => 'collocation', 'course' => $course->slug, 'lesson_id' => $lesson->id];
    PracticeKit::resetLimiters($me);

    BattleHttp::post($me, '', $body)->assertCreated();
    foreach (range(2, 5) as $_) {
        BattleHttp::post($me, '', $body)->assertStatus(409);   // busy with the first one: refused, but it counts
    }
    BattleHttp::post($me, '', $body)->assertStatus(429)->assertHeader('Retry-After');
    BattleHttp::get($me, '')->assertOk();

    // The bucket of the solo sessions is the same `twa-practice-open`: when it is full an invitation is refused with the invitations' bucket whole.
    PracticeKit::exhaust('twa-practice-open', $friend, 10);
    BattleHttp::post($friend, '', $body)->assertStatus(429);
});

it('gives the audio a bucket of its own, 60 clips a minute, and `twa-api` is not touched by them', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $url = BattleHttp::view($me, $id)->json('audio_preload.0');
    PracticeKit::resetLimiters($me);

    foreach (range(1, 60) as $_) {
        BattleHttp::audio($me, $url)->assertOk();
    }
    BattleHttp::audio($me, $url)->assertStatus(429)->assertHeader('Retry-After');

    // 60 clips and a refusal later the general budget is whole.
    BattleHttp::view($me, $id)->assertOk();
    BattleHttp::get($me, '')->assertOk();
    BattleHttp::audio($friend, $url)->assertOk();
});
