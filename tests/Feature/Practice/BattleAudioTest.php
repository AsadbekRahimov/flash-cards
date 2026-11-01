<?php

declare(strict_types=1);

use App\Domain\Practice\Enums\PracticeMode;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Practice\BattleHttp;
use Tests\Support\Practice\BattleKit;
use Tests\Support\Practice\PracticeKit;

/*
 * docs/16 §7.4, §10.2, §10.3: the audio of a battle - the clips of a dictation, streamed by the opaque id of the element from the disk the
 * snapshot names. Only to the two players; `private, no-store`; the path never leaves the server. The clip of a round that has not begun is
 * not handed out (404, the same as for an element that is not there) - except in the room and the countdown, where all five are loaded
 * before "Ready". A file that is not there is told (503) and nothing else: the report that voids the match is `audio-failed`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(BattleKit::T0);
    Storage::fake('practice_audio');
    Queue::fake();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** The URLs of the five clips of a battle as the room lists them. @return list<string> */
function battleClipUrls(int $id, Learner $viewer): array
{
    $urls = BattleHttp::view($viewer, $id)->assertOk()->json('audio_preload');
    expect($urls)->toBeArray()->toHaveCount(5);

    return $urls;
}

it('hands the five clips of a dictation to both players in the room and in the countdown: audio/mpeg, private, no-store, the bytes of the file - and to nobody else', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $elements = BattleKit::fresh($id)->snapshot['elements'];
    $urls = battleClipUrls($id, $me);

    foreach ($urls as $index => $url) {
        $bytes = Storage::disk('practice_audio')->get($elements[$index]['audio_path']);
        foreach ([$me, $friend] as $player) {
            $response = BattleHttp::audio($player, $url)->assertOk();

            expect($response->headers->get('Content-Type'))->toBe('audio/mpeg')
                ->and($response->headers->get('Content-Length'))->toBe((string) strlen((string) $bytes))
                ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public')
                ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
                ->and($response->streamedContent())->toBe($bytes);
        }
    }

    // The countdown hands out the same five.
    BattleHttp::ready($me, $id)->assertOk();
    BattleHttp::ready($friend, $id)->assertOk()->assertJsonPath('status', 'countdown');
    foreach ($urls as $url) {
        BattleHttp::audio($friend, $url)->assertOk();
    }

    // Nobody else: a stranger gets the 404 of a battle that is not there; without a token it is 401.
    $stranger = Learner::factory()->create();
    $missing = BattleHttp::audio($stranger, "/api/twa/practice/battles/999999/audio/{$elements[0]['id']}");
    expect($missing->assertNotFound()->assertJsonPath('error.code', 'battle_not_found')->json())->toBe(BattleHttp::audio($stranger, $urls[0])->assertNotFound()->json());
    test()->flushHeaders()->get($urls[0])->assertUnauthorized();
});

it('does not hand out the clip of a round that has not begun - the same 404 as for an element that is not there - until the round begins, and keeps handing out the rounds that were', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();

    // An invitation that is not accepted has no clips to hand out.
    $invited = (int) BattleHttp::invite($me, $friendship, PracticeMode::Dictation, $course, $lesson)->assertCreated()->json('id');
    $firstOfInvited = BattleKit::element(BattleKit::fresh($invited), 1)['id'];
    foreach ([$me, $friend] as $player) {
        BattleHttp::audio($player, "/api/twa/practice/battles/{$invited}/audio/{$firstOfInvited}")->assertNotFound()->assertJsonPath('error.code', 'battle_not_found');
    }
    BattleHttp::cancel($me, $invited)->assertOk();

    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $ids = array_column(BattleKit::fresh($id)->snapshot['elements'], 'id');
    $url = fn (int $round): string => "/api/twa/practice/battles/{$id}/audio/{$ids[$round - 1]}";
    $nobody = BattleHttp::audio($me, "/api/twa/practice/battles/{$id}/audio/eNoSuchEl00")->assertNotFound()->json();

    // Round 1 is on: its clip is there, the four that follow are not.
    BattleHttp::audio($me, $url(1))->assertOk();
    foreach ([2, 3, 4, 5] as $round) {
        foreach ([$me, $friend] as $player) {
            expect(BattleHttp::audio($player, $url($round))->assertNotFound()->json())->toBe($nobody);
        }
    }

    // Each round opens its own clip, and the ones behind stay (to listen again in the review).
    for ($round = 1; $round <= 5; $round++) {
        $element = BattleKit::element(BattleKit::fresh($id), $round);
        BattleHttp::audio($friend, $url($round))->assertOk();
        BattleHttp::answer($me, $id, $round, BattleKit::correct($element))->assertOk();
        BattleHttp::answer($friend, $id, $round, BattleKit::wrong($element))->assertOk();
        battleAudioWait($id, $me, $friend, 4);
        foreach (range(1, $round) as $behind) {
            BattleHttp::audio($me, $url($behind))->assertOk();
        }
        if ($round < 5) {
            BattleHttp::audio($me, $url($round + 1))->assertOk();   // the round that has begun
            if ($round + 2 <= 5) {
                BattleHttp::audio($me, $url($round + 2))->assertNotFound();   // the one after it has not
            }
        }
    }
    expect(BattleHttp::view($me, $id)->json('status'))->toBe('finished');
    foreach (range(1, 5) as $round) {
        BattleHttp::audio($me, $url($round))->assertOk();   // a match that is over keeps its last round: all five
    }
});

it('hands out the clips of the rounds a surrendered match reached, and no more', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::playing($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $ids = array_column(BattleKit::fresh($id)->snapshot['elements'], 'id');
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    BattleHttp::answer($me, $id, 1, BattleKit::correct($element))->assertOk();
    BattleHttp::answer($friend, $id, 1, BattleKit::correct($element))->assertOk();
    battleAudioWait($id, $me, $friend, 4);   // round 2 is on
    BattleHttp::surrender($friend, $id)->assertOk();

    foreach ([1, 2] as $round) {
        BattleHttp::audio($me, "/api/twa/practice/battles/{$id}/audio/{$ids[$round - 1]}")->assertOk();
    }
    foreach ([3, 4, 5] as $round) {
        BattleHttp::audio($me, "/api/twa/practice/battles/{$id}/audio/{$ids[$round - 1]}")->assertNotFound();
    }
});

it('has no clip to hand out in a battle of a mode without audio: every element id is a 404', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Collocation, $course, $lesson);

    foreach (BattleKit::fresh($id)->snapshot['elements'] as $element) {
        BattleHttp::audio($me, "/api/twa/practice/battles/{$id}/audio/{$element['id']}")->assertNotFound()->assertJsonPath('error.code', 'battle_not_found');
    }
    expect(BattleHttp::view($me, $id)->json('audio_preload'))->toBeNull();
});

it('says 503 practice_audio_unavailable for a file that is not there or is empty - and says only that: the match is not touched, the report that voids it is audio-failed', function (): void {
    [$me, $friend, $course, $lesson, $friendship] = BattleKit::world();
    $id = BattleHttp::room($me, $friend, $friendship, PracticeMode::Dictation, $course, $lesson);
    $element = BattleKit::element(BattleKit::fresh($id), 1);
    $url = battleClipUrls($id, $me)[0];
    $path = $element['audio_path'];
    $bytes = (string) Storage::disk('practice_audio')->get($path);
    $before = BattleKit::fresh($id);

    Storage::disk('practice_audio')->delete($path);
    BattleHttp::audio($me, $url)->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');
    Storage::disk('practice_audio')->put($path, '');
    BattleHttp::audio($me, $url)->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');

    $after = BattleKit::fresh($id);
    expect([$after->status, $after->state_version])->toBe([$before->status, $before->state_version])
        ->and(ProductEvent::query()->where('name', 'practice.audio_failed')->count())->toBe(0);   // one report, not two: audio-failed writes the event

    // The file comes back: it plays.
    Storage::disk('practice_audio')->put($path, $bytes);
    expect(BattleHttp::audio($me, $url)->assertOk()->streamedContent())->toBe($bytes);

    // The report finds it missing again, voids the match, and writes the event once.
    Storage::disk('practice_audio')->delete($path);
    BattleHttp::audioFailed($friend, $id, $element['id'])->assertOk()->assertJsonPath('verified', true)->assertJsonPath('battle.end_reason', 'voided');
    expect(ProductEvent::query()->where('name', 'practice.audio_failed')->count())->toBe(1);
});

/** Time goes on (both players poll all along, as their screens do). */
function battleAudioWait(int $id, Learner $a, Learner $b, float $seconds): void
{
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMilliseconds((int) ($seconds * 1000)));
    foreach ([$a, $b] as $player) {
        PracticeKit::resetLimiters($player);
        BattleHttp::poll($player, $id)->assertOk();
    }
}
