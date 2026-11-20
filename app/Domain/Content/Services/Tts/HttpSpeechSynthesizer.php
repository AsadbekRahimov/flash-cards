<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * `TTS_DRIVER=http` (docs/26 §5.1): the edge-tts compatible service of `TTS_SERVICE_URL` (`POST {url}/tts`, `{word, voice}`,
 * answers the mp3), kept for local development. An empty URL is `tts_service_not_configured`, as before the driver existed.
 * The service is free: neither the pace limit nor the monthly character budget of the `google` driver apply (docs/26 §9.3).
 */
final class HttpSpeechSynthesizer implements SpeechSynthesizer
{
    public function synthesize(string $text, string $voice): array
    {
        $baseUrl = rtrim((string) config('twa.tts.service_url'), '/');
        if ($baseUrl === '') {
            throw SpeechSynthesisException::notConfigured();
        }

        try {
            $response = Http::timeout((int) config('twa.tts.timeout', 8))
                ->accept('audio/mpeg')
                ->post($baseUrl.'/tts', array_filter([
                    'word' => $text,
                    'voice' => $voice,
                ], fn (string $value): bool => $value !== ''));
        } catch (ConnectionException $e) {
            throw SpeechSynthesisException::failed($e);
        }

        if (! $response->successful()) {
            throw SpeechSynthesisException::failed();
        }

        return [
            'body' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'audio/mpeg',
        ];
    }
}
