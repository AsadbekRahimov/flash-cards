<?php

declare(strict_types=1);

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Twa\Services\JwtService;
use App\Models\Learner;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/*
 * docs/26 §9.4: GET /api/twa/tts only hands out a pre-generated file (302 to the URL of the audio disk) or 404 - nothing is
 * synthesized on behalf of a learner, whatever the driver.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');
    Config::set('twa.tts.voice', 'en-US-AriaNeural');
    Config::set('twa.tts.audio_version', 1);
    Config::set('twa.tts.audio_disk', 'public');
    Storage::fake('public');
    Http::preventStrayRequests();
    Http::fake();
});

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

function twaTtsStoredPath(string $text): string
{
    return 'audio/'.sha1(mb_strtolower($text).'|en-US-AriaNeural|1').'.mp3';
}

it('redirects to the stored file of the text on the audio disk, private for an hour', function (): void {
    $path = twaTtsStoredPath('ice cream');
    Storage::disk('public')->put($path, 'stored-bytes');

    $response = twaTtsRequest(Learner::factory()->create(), 'word=Ice%20%20Cream');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(Storage::disk('public')->url($path))
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600')->not->toContain('public')
        ->and($response->getContent())->not->toContain('stored-bytes');
    Http::assertNothingSent();
});

it('answers 404 tts_not_found, not cached, for a text without a file - and never synthesizes', function (?string $driver): void {
    Config::set('twa.tts.driver', $driver);
    $synth = Mockery::mock(SpeechSynthesizer::class);
    $synth->shouldNotReceive('synthesize');
    app()->instance(SpeechSynthesizer::class, $synth);

    $response = twaTtsRequest(Learner::factory()->create(), 'word=resilient');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(404)
        ->and($payload['error']['code'])->toBe('tts_not_found')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Http::assertNothingSent();
})->with(['http' => 'http', 'google' => 'google', 'null' => 'null']);

it('an empty leftover file is not served', function (): void {
    Storage::disk('public')->put(twaTtsStoredPath('resilient'), '');

    expect(twaTtsRequest(Learner::factory()->create(), 'word=resilient')->getStatusCode())->toBe(404);
});

it('ignores a voice parameter: only the configured voice has files', function (): void {
    $path = twaTtsStoredPath('resilient');
    Storage::disk('public')->put($path, 'stored-bytes');

    $response = twaTtsRequest(Learner::factory()->create(), 'word=resilient&voice=en-GB-RyanNeural');

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(Storage::disk('public')->url($path));
});

it('rejects unsafe TTS word input', function (): void {
    $response = twaTtsRequest(Learner::factory()->create(), 'word=%3Cscript%3E');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(422)
        ->and($payload['errors'])->toHaveKey('word');
});

it('requires a word', function (): void {
    expect(twaTtsRequest(Learner::factory()->create(), '')->getStatusCode())->toBe(422);
});

it('drops the live synthesis settings', function (): void {
    expect(config('twa.tts'))->not->toHaveKeys(['live_timeout', 'live_connect_timeout', 'live_concurrency'])
        ->and(file_exists(app_path('Domain/Twa/Services/TtsFallbackService.php')))->toBeFalse()
        ->and((string) file_get_contents(base_path('.env.example')))->not->toContain('TTS_LIVE_');
});
