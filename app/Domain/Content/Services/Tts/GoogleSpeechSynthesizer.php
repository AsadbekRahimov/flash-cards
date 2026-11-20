<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * `TTS_DRIVER=google` (docs/26 §9.1-9.3): Google Cloud Text-to-Speech `text:synthesize`, MP3 at 24 kHz, authorised by the
 * self-signed JWT of {@see GoogleServiceAccount}. The order of a call: the halt marker -> the voice and the length -> the
 * key -> the pace ({@see TtsRequestThrottle}) -> the monthly budget ({@see TtsCharacterBudget}) -> HTTP -> a refund or the
 * audio. Every failure is a {@see SpeechSynthesisException} whose message is a code; neither the key, the token nor the body
 * of an answer (it may quote the text) ever reaches a message or a log line.
 *
 * The endpoint, the sample rate and the connect timeout are constants, not settings: changing them changes the audio, which
 * needs a new `TTS_AUDIO_VERSION`.
 */
final class GoogleSpeechSynthesizer implements SpeechSynthesizer
{
    public const ENDPOINT = 'https://texttospeech.googleapis.com/v1/text:synthesize';

    public const SAMPLE_RATE_HERTZ = 24_000;

    public const CONNECT_TIMEOUT = 5;

    /** The request limit of Google, in bytes of the text. */
    public const MAX_TEXT_BYTES = 5_000;

    /** After `tts_auth_failed` / `tts_forbidden`: every job fails at once for this long instead of calling Google again. */
    public const HALT_KEY = 'tts:google:v1:halt';

    public const HALT_SECONDS = 600;

    public function __construct(
        private readonly GoogleServiceAccount $account,
        private readonly TtsRequestThrottle $throttle,
        private readonly TtsCharacterBudget $budget,
    ) {}

    public function synthesize(string $text, string $voice): array
    {
        $this->throwIfHalted();

        if (preg_match('/\A([a-z]{2,3}-[A-Z]{2})-[A-Za-z0-9-]+\z/', $voice, $m) !== 1) {
            throw SpeechSynthesisException::rejected();
        }
        if ($text === '' || strlen($text) > self::MAX_TEXT_BYTES) {
            throw SpeechSynthesisException::rejected();
        }

        $token = $this->account->bearerToken();
        $this->throttle->acquire();

        $chars = mb_strlen($text);
        $month = $this->budget->reserve($chars);

        $body = [
            'input' => ['text' => $text],
            'voice' => ['languageCode' => $m[1], 'name' => $voice],
            'audioConfig' => ['audioEncoding' => 'MP3', 'sampleRateHertz' => self::SAMPLE_RATE_HERTZ],
        ];

        try {
            $response = $this->send($token, $body);
            if ($response->status() === 401) {
                $response = $this->send($this->account->bearerToken(fresh: true), $body);
            }
        } catch (ConnectionException $e) {
            // Google may have synthesized (and counted) before the connection dropped: no refund.
            throw SpeechSynthesisException::failed($e);
        } catch (SpeechSynthesisException $e) {
            // The key went bad between the two attempts: nothing more was sent.
            $this->budget->refund($month, $chars);

            throw $e;
        }

        if ($response->successful()) {
            return ['body' => $this->audioOf($response), 'content_type' => 'audio/mpeg'];
        }

        $this->budget->refund($month, $chars);
        $status = $response->status();
        $errorStatus = $response->json('error.status');
        Log::warning('tts.google_refused', [
            'status' => $status,
            // An enum of Google (INVALID_ARGUMENT, PERMISSION_DENIED, ...); anything else is not logged - the body may quote the text.
            'error_status' => is_string($errorStatus) && preg_match('/\A[A-Z_]{1,64}\z/', $errorStatus) === 1 ? $errorStatus : null,
            'chars' => $chars,
        ]);

        throw match (true) {
            $status === 401 => $this->halt(SpeechSynthesisException::authFailed()),
            $status === 403 => $this->halt(SpeechSynthesisException::forbidden()),
            $status === 408, $status === 429 => SpeechSynthesisException::rateLimited(),
            $status >= 500 => SpeechSynthesisException::failed(),
            default => SpeechSynthesisException::rejected(),
        };
    }

    /**
     * No `->retry()` (the queue retries the job), no `->dump()`, no debug option, no request logger: the Authorization header
     * stays inside this call.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws ConnectionException
     */
    private function send(#[\SensitiveParameter] string $token, array $body): Response
    {
        return Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(max(1, (int) config('twa.tts.google.timeout', 20)))
            ->post(self::ENDPOINT, $body);
    }

    private function audioOf(Response $response): string
    {
        $content = $response->json('audioContent');
        $bytes = is_string($content) ? base64_decode($content, true) : false;
        if ($bytes === false || $bytes === '') {
            throw SpeechSynthesisException::badAudio();
        }

        return $bytes;
    }

    private function throwIfHalted(): void
    {
        $marker = Cache::get(self::HALT_KEY);
        // docs/19 §3.11: a cached value is checked, not trusted.
        if (! is_array($marker)) {
            return;
        }

        match ($marker['code'] ?? null) {
            SpeechSynthesisException::AUTH_FAILED => throw SpeechSynthesisException::authFailed(),
            SpeechSynthesisException::FORBIDDEN => throw SpeechSynthesisException::forbidden(),
            default => null,
        };
    }

    private function halt(SpeechSynthesisException $e): SpeechSynthesisException
    {
        Cache::put(self::HALT_KEY, ['code' => $e->getMessage()], self::HALT_SECONDS);

        return $e;
    }
}
