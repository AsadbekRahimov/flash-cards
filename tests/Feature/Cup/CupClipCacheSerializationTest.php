<?php

declare(strict_types=1);

use App\Domain\Cup\Services\CupRoundService;
use App\Domain\Operations\Support\PureCache;
use App\Models\CupDay;
use App\Models\CupRoundAttempt;
use App\Models\Learner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Cup\CupKit;
use Tests\Support\Practice\PracticeKit;

/*
 * The clip of a `listen` task of a cup round (docs/04 A.23) with caches that SERIALIZE like Redis does in production
 * (`cache.serializable_classes = false`, docs/19 §3 rule 2): the array store of the tests keeps values as they are and would
 * never notice an object put into the cache on the way from the start of the round to its clip - Redis would give it back as
 * __PHP_Incomplete_Class. Both the default store and the pure one (`PureCache`) serialize here, so the run on sqlite fails
 * the way PostgreSQL + Redis would.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.serializable_classes' => false,
        'cache.stores.array.serialize' => true,
        'cache.default' => 'array',
        'cache.pure_store' => 'array',
    ]);
    Cache::purge();
    CupKit::open();
    CupKit::course();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param array<string, mixed> $body */
function cupClipCall(Learner $learner, string $method, string $path, array $body = []): TestResponse
{
    return test()->withHeaders(PracticeKit::headers($learner))->json($method, '/api/twa'.$path, $body);
}

it('serves the clip of a listen task when every cache entry went through serialize and back', function (): void {
    // Both stores serialize with no class allowed: an object comes back incomplete, as from Redis.
    Cache::put('probe:object', new ArrayObject([1]), 60);
    PureCache::store()->put('probe:object', new ArrayObject([1]), 60);
    expect(Cache::get('probe:object'))->toBeInstanceOf(__PHP_Incomplete_Class::class)
        ->and(PureCache::store()->get('probe:object'))->toBeInstanceOf(__PHP_Incomplete_Class::class);
    Cache::forget('probe:object');
    PureCache::store()->forget('probe:object');

    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $day = CupDay::query()->where('cup_id', $cup->id)->where('day', 1)->firstOrFail();
    app(CupRoundService::class)->ensureDaySnapshot($day, $cup);

    // The first "back" task of the day becomes a `listen` task with a recording, as the core writes it for a word with audio.
    $snapshot = json_decode((string) DB::table('cup_days')->where('id', $day->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
    $listen = null;
    foreach ($snapshot['elements'] as $i => $element) {
        if ($element['kind'] === 'translate_back') {
            unset($element['prompt'], $element['audio']);
            $snapshot['elements'][$i] = [...$element, 'kind' => 'listen', 'audio_disk' => 'public', 'audio_path' => 'audio/cup-clip.mp3'];
            $listen = (string) $element['id'];
            break;
        }
    }
    expect($listen)->not->toBeNull();
    DB::table('cup_days')->where('id', $day->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

    $round = cupClipCall($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();
    $attempt = CupRoundAttempt::query()->findOrFail($round['attempt_id']);
    while ($round['current'] !== null && $round['current']['id'] !== $listen) {
        $round = cupClipCall($a, 'POST', "/cup-rounds/{$attempt->id}/answer", ['request_id' => (string) Str::uuid(), 'element_id' => $round['current']['id'], 'answer' => ['skip' => true]])->assertOk()->json('round');
    }
    expect($round['current']['kind'] ?? null)->toBe('listen');

    // Written right before it is read (the faked disk is one directory for every pest process of the checkout, CupContractTest).
    Storage::disk('public')->put('audio/cup-clip.mp3', 'ID3-fake-mp3');
    $clip = cupClipCall($a, 'GET', "/cup-rounds/{$attempt->id}/audio/{$listen}");
    $clip->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($clip->streamedContent())->toBe('ID3-fake-mp3');

    // The same clip again: now everything the first request cached is read back from the serialized entries.
    Storage::disk('public')->put('audio/cup-clip.mp3', 'ID3-fake-mp3');
    expect(cupClipCall($a, 'GET', "/cup-rounds/{$attempt->id}/audio/{$listen}")->assertOk()->streamedContent())->toBe('ID3-fake-mp3');
});

it('answers 503 practice_audio_unavailable, not a 500, when the file of a reached task is gone', function (): void {
    ['cup' => $cup, 'learners' => [$a]] = CupKit::running(2);
    $day = CupDay::query()->where('cup_id', $cup->id)->where('day', 1)->firstOrFail();
    app(CupRoundService::class)->ensureDaySnapshot($day, $cup);
    // Every task names a recording that is not on the disk: whichever comes first in the shuffled order of the round.
    $snapshot = json_decode((string) DB::table('cup_days')->where('id', $day->id)->value('snapshot'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($snapshot['elements'] as $i => $element) {
        $snapshot['elements'][$i] = [...$element, 'audio_disk' => 'public', 'audio_path' => 'audio/cup-missing.mp3'];
    }
    DB::table('cup_days')->where('id', $day->id)->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

    $round = cupClipCall($a, 'POST', "/cups/{$cup->id}/rounds/today")->assertCreated()->json();

    cupClipCall($a, 'GET', "/cup-rounds/{$round['attempt_id']}/audio/{$round['current']['id']}")
        ->assertStatus(503)->assertJsonPath('error.code', 'practice_audio_unavailable');
});
