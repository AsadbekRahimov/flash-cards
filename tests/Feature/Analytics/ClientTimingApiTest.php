<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\ProductEventName;
use App\Domain\Analytics\Services\ClientTimingRecorder;
use App\Domain\Billing\Services\EntitlementResolver;
use App\Domain\Twa\Services\JwtService;
use App\Domain\Twa\Services\TodayProfileCache;
use App\Http\Resources\Twa\AuthTokenResource;
use App\Models\Learner;
use App\Models\ProductEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\QueryLog;

/*
 * POST /api/twa/client-timing (docs/04 A.21, docs/12 §7.7): the timing marks of one sampled open of the Mini App. One `client.timing`
 * per open_id with exactly the listed props; a closed set of keys; 204 always; nothing while the rate is 0 or for an open outside the
 * sample; no SQL beyond the authentication on the request path; its own limiter.
 */

uses(RefreshDatabase::class);

/** Bucket 0 of `client_timing:<id>`: in the sample of any rate above 0. */
const TIMING_IN_ANY = '00000006-0000-4000-8000-000000000006';

/** Bucket 31: outside a 10 % sample, inside a 100 % one. */
const TIMING_BUCKET_31 = '11111111-1111-4111-8111-111111111111';

beforeEach(function (): void {
    config()->set('analytics.client_timing.sample_percent', 100);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-19 10:00:00', 'UTC'));
    $this->learner = Learner::factory()->create(['interface_locale' => 'uz', 'first_name' => 'Ada', 'username' => 'ada_l']);
    $this->headers = ['Authorization' => 'Bearer '.app(JwtService::class)->issue($this->learner->id)['token']];
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array<string, mixed> */
function timingBody(array $overrides = []): array
{
    return [
        'open_id' => TIMING_IN_ANY,
        'platform' => 'android',
        'cold' => true,
        'entry' => 'home',
        'auth_ready' => 310,
        'today_received' => 640,
        'button_ready' => 702,
        'first_tap' => 2150,
        'first_answer_ack' => 4890,
        'first_audio' => 3010,
        ...$overrides,
    ];
}

/** @return list<ProductEvent> */
function timingEvents(): array
{
    return ProductEvent::query()->where('name', ProductEventName::ClientTiming->value)->orderBy('id')->get()->all();
}

it('records one event with exactly the listed props and answers 204 with no body', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    $events = timingEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0]->learner_id)->toBe($this->learner->id)
        // No `class`: the server had no `/today` snapshot of this learner to tell. No name, no username, no Telegram id.
        ->and($events[0]->props)->toEqual([
            'open_id' => TIMING_IN_ANY,
            'platform' => 'android',
            'cold' => true,
            'entry' => 'home',
            'locale' => 'uz',
            'auth_ready' => 310,
            'today_received' => 640,
            'button_ready' => 702,
            'first_tap' => 2150,
            'first_answer_ack' => 4890,
            'first_audio' => 3010,
        ]);
});

it('keeps only the marks that were sent', function (): void {
    $body = timingBody(['cold' => false, 'entry' => 'deep_link']);
    unset($body['first_tap'], $body['first_answer_ack'], $body['first_audio'], $body['button_ready']);

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', $body)->assertNoContent();

    expect(timingEvents()[0]->props)->toEqual([
        'open_id' => TIMING_IN_ANY, 'platform' => 'android', 'cold' => false, 'entry' => 'deep_link', 'locale' => 'uz',
        'auth_ready' => 310, 'today_received' => 640,
    ]);
});

it('takes class / solo from the cached /today snapshot of the learner', function (bool $assignments): void {
    app(TodayProfileCache::class)->put($this->learner, EntitlementResolver::version($this->learner->id), [
        'lapsed' => null, 'groups' => $assignments, 'assignments' => $assignments, 'until' => null,
    ]);

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    expect(timingEvents()[0]->props['class'])->toBe($assignments);
})->with(['class' => true, 'solo' => false]);

it('normalises a platform outside the closed list to other', function (string $sent, string $stored): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody(['platform' => $sent]))->assertNoContent();

    expect(timingEvents()[0]->props['platform'])->toBe($stored);
})->with([
    'ios' => ['ios', 'ios'],
    'tdesktop' => ['tdesktop', 'tdesktop'],
    'webk' => ['webk', 'webk'],
    'unknown' => ['unknown', 'unknown'],
    'new platform' => ['visionos', 'other'],
    'case' => ['Android', 'other'],
]);

it('records an open once, however often it is sent', function (): void {
    $post = fn (array $body) => $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', $body)->assertNoContent();

    $post(timingBody());
    $post(timingBody(['first_audio' => 9000]));
    // The same id in capitals is the same open.
    $post(timingBody(['open_id' => strtoupper(TIMING_IN_ANY)]));

    expect(timingEvents())->toHaveCount(1)
        ->and(timingEvents()[0]->props['first_audio'])->toBe(3010)
        ->and(Cache::has('analytics:client_timing:v1:'.TIMING_IN_ANY))->toBeTrue()
        ->and(ClientTimingRecorder::markerKey(TIMING_IN_ANY))->toBe('analytics:client_timing:v1:'.TIMING_IN_ANY);

    // The marker lives a day, then expires.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 10:01:00', 'UTC'));
    expect(Cache::has('analytics:client_timing:v1:'.TIMING_IN_ANY))->toBeFalse();
});

it('records nothing while the rate is 0, and still answers 204', function (): void {
    config()->set('analytics.client_timing.sample_percent', 0);

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    expect(timingEvents())->toBe([])
        ->and(Cache::has('analytics:client_timing:v1:'.TIMING_IN_ANY))->toBeFalse();
});

it('records only the opens of the sample: the client and the server hash the same id', function (): void {
    config()->set('analytics.client_timing.sample_percent', 10);

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody(['open_id' => TIMING_BUCKET_31]))->assertNoContent();
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    expect(array_map(static fn (ProductEvent $e): mixed => $e->props['open_id'], timingEvents()))->toBe([TIMING_IN_ANY])
        ->and(ClientTimingRecorder::sampled(TIMING_BUCKET_31, 31))->toBeFalse()
        ->and(ClientTimingRecorder::sampled(TIMING_BUCKET_31, 32))->toBeTrue()
        ->and(ClientTimingRecorder::sampled(TIMING_IN_ANY, 1))->toBeTrue()
        ->and(ClientTimingRecorder::sampled(TIMING_IN_ANY, 0))->toBeFalse();
});

it('costs only the authentication on the request path; the event is one insert after the response', function (): void {
    $post = fn () => $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    $first = QueryLog::during($post);
    $repeat = QueryLog::during($post);

    expect(QueryLog::matching($first, '/^insert into ["`]product_events["`]/'))->toHaveCount(1)
        ->and(count($first))->toBe(2)
        ->and($repeat)->toHaveCount(1)
        ->and($repeat[0])->toMatch('/from ["`]learners["`]/');
});

it('refuses an invalid body with 422 and records nothing', function (array $overrides, string $field): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody($overrides))
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);

    expect(timingEvents())->toBe([]);
})->with([
    'open_id missing' => [['open_id' => null], 'open_id'],
    'open_id not a uuid' => [['open_id' => 'abc'], 'open_id'],
    'platform missing' => [['platform' => null], 'platform'],
    'platform too long' => [['platform' => str_repeat('a', 33)], 'platform'],
    'cold missing' => [['cold' => null], 'cold'],
    'cold not a bool' => [['cold' => 'yes'], 'cold'],
    'entry unknown' => [['entry' => 'reminder'], 'entry'],
    'negative offset' => [['auth_ready' => -1], 'auth_ready'],
    'offset over ten minutes' => [['first_audio' => 600_001], 'first_audio'],
    'fractional offset' => [['button_ready' => 12.5], 'button_ready'],
    'offset as text' => [['first_tap' => 'soon'], 'first_tap'],
    'null offset' => [['first_answer_ack' => null], 'first_answer_ack'],
]);

it('accepts the bounds of an offset', function (int $value): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody(['auth_ready' => $value]))->assertNoContent();

    expect(timingEvents()[0]->props['auth_ready'])->toBe($value);
})->with([0, 600_000]);

it('refuses unknown keys, the learner of another and anything about the device', function (array $extra): void {
    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody($extra))
        ->assertStatus(422)
        ->assertJsonValidationErrors(array_keys($extra));

    expect(timingEvents())->toBe([]);
})->with([
    'learner_id' => [['learner_id' => 1]],
    'user agent' => [['user_agent' => 'Mozilla/5.0']],
    'free text' => [['note' => 'slow']],
    'unknown mark' => [['first_paint' => 120]],
]);

it('writes for the learner of the token only', function (): void {
    $other = Learner::factory()->create(['interface_locale' => 'ru']);

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();

    expect(timingEvents())->toHaveCount(1)
        ->and(timingEvents()[0]->learner_id)->toBe($this->learner->id)
        ->and(ProductEvent::query()->where('learner_id', $other->id)->exists())->toBeFalse();
});

it('needs the TWA token', function (): void {
    $this->postJson('/api/twa/client-timing', timingBody())->assertUnauthorized();

    expect(timingEvents())->toBe([]);
});

it('has a limiter of its own: the 7th call in a minute is 429, for that learner only', function (): void {
    for ($i = 0; $i < 6; $i++) {
        $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())->assertNoContent();
    }

    $this->withHeaders($this->headers)->postJson('/api/twa/client-timing', timingBody())
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    $other = Learner::factory()->create();
    $this->withHeaders(['Authorization' => 'Bearer '.app(JwtService::class)->issue($other->id)['token']])
        ->postJson('/api/twa/client-timing', timingBody(['open_id' => TIMING_BUCKET_31]))
        ->assertNoContent();

    expect(timingEvents())->toHaveCount(2);
});

it('hands the sample rate to the client in POST /auth, from the config only', function (int $percent, int $sent): void {
    config()->set('analytics.client_timing.sample_percent', $percent);

    expect((new AuthTokenResource([
        'jwt' => ['token' => 't', 'expires_in' => 60, 'expires_at' => 0],
        'learner' => $this->learner,
        'account_restored' => null,
    ]))->toArray(request())['client_timing'])->toBe(['sample_percent' => $sent]);
})->with([
    'default' => [10, 10],
    'off' => [0, 0],
    'all' => [100, 100],
    'clamped above' => [150, 100],
    'clamped below' => [-5, 0],
]);

it('reads the same sample rule as the client', function (): void {
    // resources/twa/src/composables/__tests__/useTimingMarks.spec.ts pins the same buckets for the same ids.
    $client = (string) file_get_contents(resource_path('twa/src/composables/useTimingMarks.ts'));

    expect($client)->toContain("SAMPLE_SALT = '".ClientTimingRecorder::SAMPLE_SALT."'")
        ->and(crc32('client_timing:'.TIMING_IN_ANY) % 100)->toBe(0)
        ->and(crc32('client_timing:'.TIMING_BUCKET_31) % 100)->toBe(31);
});
