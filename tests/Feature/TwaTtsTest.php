<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function twaTtsHeaders(Learner $learner): array
{
    /** @var JwtService $jwt */
    $jwt = app(JwtService::class);
    $token = $jwt->issue($learner->id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

function twaTtsRequest(Learner $learner, string $query): Response
{
    $headers = twaTtsHeaders($learner);

    $request = Request::create('/api/twa/tts?'.$query, 'GET', server: [
        'HTTP_AUTHORIZATION' => $headers['Authorization'],
        'HTTP_ACCEPT' => 'application/json',
    ]);

    return app(Kernel::class)->handle($request);
}

it('proxies TTS fallback audio from the configured service', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');

    $learner = Learner::factory()->create();

    Http::fake([
        'http://tts.test/tts' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $response = twaTtsRequest($learner, 'word=resilient&voice=en-US-AriaNeural');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toContain('audio/mpeg');
    expect($response->getContent())->toBe('mp3-bytes');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://tts.test/tts'
        && $request['word'] === 'resilient'
        && $request['voice'] === 'en-US-AriaNeural');
});

it('returns 503 when TTS fallback service is not configured', function (): void {
    Config::set('twa.tts.service_url', '');

    $learner = Learner::factory()->create();

    $response = twaTtsRequest($learner, 'word=resilient');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(503);
    expect($payload['error']['code'])->toBe('tts_unavailable');
});

it('rejects unsafe TTS word input', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');

    $learner = Learner::factory()->create();

    $response = twaTtsRequest($learner, 'word=%3Cscript%3E');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(422);
    expect($payload['errors'])->toHaveKey('word');
});

it('calls the service with the short live timeouts and marks the audio private', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');
    Config::set('twa.tts.timeout', 8);
    Config::set('twa.tts.live_timeout', 3);
    Config::set('twa.tts.live_connect_timeout', 2);
    $seen = [];
    Http::fake(function ($request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']);
    });

    $response = twaTtsRequest(Learner::factory()->create(), 'word=resilient');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public')
        ->and($seen['timeout'] ?? null)->toBe(3)
        ->and($seen['connect_timeout'] ?? null)->toBe(2);
});

it('refuses with the 503 of an unavailable service when every live slot is taken, without calling it', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');
    Config::set('twa.tts.live_concurrency', 2);
    Http::fake();
    $held = [Cache::lock('tts:live:slot:0', 30), Cache::lock('tts:live:slot:1', 30)];
    foreach ($held as $lock) {
        expect($lock->get())->toBeTrue();
    }

    $response = twaTtsRequest(Learner::factory()->create(), 'word=resilient');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(503)->and($payload['error']['code'])->toBe('tts_unavailable');
    Http::assertNothingSent();

    $held[1]->release();
    Http::fake(['http://tts.test/tts' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);
    expect(twaTtsRequest(Learner::factory()->create(), 'word=resilient')->getStatusCode())->toBe(200)
        // The slot is given back: the next call takes it again.
        ->and(twaTtsRequest(Learner::factory()->create(), 'word=resilient')->getStatusCode())->toBe(200);
});
