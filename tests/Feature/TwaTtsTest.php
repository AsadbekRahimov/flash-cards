<?php

declare(strict_types=1);

use App\Domain\Twa\Services\JwtService;
use App\Models\Student;
use App\Models\TelegramGroup;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/** @return array<string, string> */
function twaTtsHeaders(Student $student): array
{
    /** @var JwtService $jwt */
    $jwt = app(JwtService::class);
    $token = $jwt->issue($student->id, (int) $student->telegram_group_id)['token'];

    return ['Authorization' => 'Bearer '.$token];
}

function twaTtsRequest(Student $student, string $query): Response
{
    $headers = twaTtsHeaders($student);

    $request = Request::create('/api/twa/tts?'.$query, 'GET', server: [
        'HTTP_AUTHORIZATION' => $headers['Authorization'],
        'HTTP_ACCEPT' => 'application/json',
    ]);

    return app(Kernel::class)->handle($request);
}

it('proxies TTS fallback audio from the configured service', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    Http::fake([
        'http://tts.test/tts' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $response = twaTtsRequest($student, 'word=resilient&voice=en-US-AriaNeural');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toContain('audio/mpeg');
    expect($response->getContent())->toBe('mp3-bytes');

    Http::assertSent(fn ($request): bool => $request->url() === 'http://tts.test/tts'
        && $request['word'] === 'resilient'
        && $request['voice'] === 'en-US-AriaNeural');
});

it('returns 503 when TTS fallback service is not configured', function (): void {
    Config::set('twa.tts.service_url', '');

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    $response = twaTtsRequest($student, 'word=resilient');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(503);
    expect($payload['error']['code'])->toBe('tts_unavailable');
});

it('rejects unsafe TTS word input', function (): void {
    Config::set('twa.tts.service_url', 'http://tts.test');

    $group = TelegramGroup::factory()->create(['status' => 'active']);
    $student = Student::factory()->create(['telegram_group_id' => $group->id]);

    $response = twaTtsRequest($student, 'word=%3Cscript%3E');
    $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($response->getStatusCode())->toBe(422);
    expect($payload['errors'])->toHaveKey('word');
});
