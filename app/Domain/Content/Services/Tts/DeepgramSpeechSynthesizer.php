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
 * `TTS_DRIVER=deepgram` (docs/26 §10): Deepgram Aura-2, `POST /v1/speak?model=<voice>&encoding=mp3&bit_rate=<n>` with
 * `{"text": ...}`, the answer is the mp3 itself. Authorised by `Authorization: Token <DEEPGRAM_API_KEY>`. The order of a call,
 * as in {@see GoogleSpeechSynthesizer}: the halt marker -> the voice and the length -> the key -> the pace
 * ({@see TtsRequestThrottle}, its own limiter) -> the budget ({@see TtsCharacterBudget}: one counter for the whole credit, not a
 * month) -> HTTP -> a refund or the audio. Every failure is a {@see SpeechSynthesisException} whose message is a code; neither the
 * key nor the body of an answer (it may quote the text) ever reaches a message, a `previous` or a log line.
 *
 * The voice is the model of Deepgram (`aura-2-thalia-en`). The endpoint and the connect timeout are constants; the bit rate is a
 * setting (`DEEPGRAM_TTS_BIT_RATE`), and changing it changes the audio, which needs a new `TTS_AUDIO_VERSION`.
 */
final class DeepgramSpeechSynthesizer implements SpeechSynthesizer
{
    public const ENDPOINT = 'https://api.deepgram.com/v1/speak';

    public const CONNECT_TIMEOUT = 5;

    /** The default voice: American, "clear, confident" - the clearest on single words of the voices Deepgram recommends. */
    public const DEFAULT_VOICE = 'aura-2-thalia-en';

    /** The bit rates of mp3 Deepgram takes; anything else in the setting falls back to {@see DEFAULT_BIT_RATE}. */
    public const BIT_RATES = [32_000, 48_000];

    public const DEFAULT_BIT_RATE = 48_000;

    /** The request limit of Deepgram, in characters of the text (`DEEPGRAM_TTS_MAX_CHARS` may lower it). */
    public const MAX_TEXT_CHARS = 2_000;

    /** After `tts_auth_failed` / `tts_forbidden` / `tts_payment_required`: every job fails at once for this long instead of calling. */
    public const HALT_KEY = 'tts:deepgram:v1:halt';

    public const HALT_SECONDS = 600;

    /**
     * A model of Deepgram: `aura-2-{name}-{language}` (Aura-2) or `aura-{name}-{language}` (Aura). Anything else (a voice of Google
     * like `en-US-Neural2-F`, of edge-tts like `en-US-AriaNeural`) is refused before the call: Deepgram would answer 400 for every word.
     */
    public const VOICE_PATTERN = '/\Aaura-(?:2-)?[a-z]+-[a-z]{2}\z/';

    /** The codes of the halt marker. */
    private const HALT_CODES = [
        SpeechSynthesisException::AUTH_FAILED,
        SpeechSynthesisException::FORBIDDEN,
        SpeechSynthesisException::PAYMENT_REQUIRED,
    ];

    public function __construct(
        private readonly TtsRequestThrottle $throttle,
        private readonly TtsCharacterBudget $budget,
    ) {}

    public function synthesize(string $text, string $voice): array
    {
        $this->throwIfHalted();

        if (! self::supportsVoice($voice)) {
            throw SpeechSynthesisException::voiceUnsupported();
        }
        if ($text === '') {
            throw SpeechSynthesisException::rejected();
        }
        $chars = mb_strlen($text);
        if ($chars > self::maxChars()) {
            throw SpeechSynthesisException::textTooLong();
        }

        $key = self::apiKey();
        if ($key === '') {
            throw SpeechSynthesisException::notConfigured();
        }

        $this->throttle->acquire(ConfiguredSpeechSynthesizer::DEEPGRAM);
        $period = $this->budget->reserve($chars, ConfiguredSpeechSynthesizer::DEEPGRAM);

        try {
            $response = $this->send($key, $voice, $text);
        } catch (ConnectionException $e) {
            // Deepgram may have synthesized (and billed) before the connection dropped: no refund. The cause is copied without its
            // chain: the exception of Guzzle under it holds the request, and the request holds the key.
            throw SpeechSynthesisException::failed(new ConnectionException(str_replace($key, '[key]', $e->getMessage())));
        }

        if ($response->successful()) {
            return ['body' => $this->audioOf($response), 'content_type' => 'audio/mpeg'];
        }

        $this->budget->refund($period, $chars);
        $status = $response->status();
        $errCode = $response->json('err_code');
        $requestId = $response->json('request_id');
        Log::warning('tts.deepgram_refused', [
            'status' => $status,
            // An enum of Deepgram (TOO_MANY_REQUESTS, INVALID_AUTH, ...); anything else is not logged - the body may quote the text.
            'err_code' => is_string($errCode) && preg_match('/\A[A-Z_]{1,64}\z/', $errCode) === 1 ? $errCode : null,
            // The id Deepgram support asks for; only when it looks like one.
            'request_id' => is_string($requestId) && preg_match('/\A[0-9a-fA-F-]{8,64}\z/', $requestId) === 1 ? $requestId : null,
            'chars' => $chars,
        ]);

        throw match (true) {
            $status === 401 => $this->halt(SpeechSynthesisException::authFailed()),
            $status === 402 => $this->halt(SpeechSynthesisException::paymentRequired()),
            $status === 403 => $this->halt(SpeechSynthesisException::forbidden()),
            $status === 408, $status === 429 => SpeechSynthesisException::rateLimited(),
            $status >= 500 => SpeechSynthesisException::failed(),
            default => SpeechSynthesisException::rejected(),
        };
    }

    /**
     * No `->retry()` (the queue retries the job), no `->dump()`, no debug option, no request logger: the Authorization header
     * stays inside this call. The voice goes into the query as it is: {@see VOICE_PATTERN} lets through letters, digits and dashes.
     *
     * @throws ConnectionException
     */
    private function send(#[\SensitiveParameter] string $key, string $voice, string $text): Response
    {
        $query = http_build_query(['model' => $voice, 'encoding' => 'mp3', 'bit_rate' => self::bitRate()]);

        return Http::withToken($key, 'Token')
            ->accept('audio/mpeg')
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(max(1, (int) config('twa.tts.deepgram.timeout', 20)))
            ->post(self::ENDPOINT.'?'.$query, ['text' => $text]);
    }

    /** The mp3 of a 200: not empty and not a JSON or a text page (a proxy, an error with the wrong code). */
    private function audioOf(Response $response): string
    {
        $body = $response->body();
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($body === '' || str_contains($type, 'json') || str_starts_with($type, 'text/')) {
            throw SpeechSynthesisException::badAudio();
        }

        return $body;
    }

    /** `DEEPGRAM_API_KEY`, trimmed; empty when it is not set. */
    private static function apiKey(): string
    {
        return trim((string) config('twa.tts.deepgram.api_key'));
    }

    /** A key is set (`/health/ready` and the reconciliation ask it; the key itself never leaves this class). */
    public static function hasKey(): bool
    {
        return self::apiKey() !== '';
    }

    /** The longest text of one request: `DEEPGRAM_TTS_MAX_CHARS`, at most {@see MAX_TEXT_CHARS}, at least 1. */
    public static function maxChars(): int
    {
        return max(1, min(self::MAX_TEXT_CHARS, (int) config('twa.tts.deepgram.max_chars', self::MAX_TEXT_CHARS)));
    }

    /** `DEEPGRAM_TTS_BIT_RATE` when Deepgram takes it for mp3, else {@see DEFAULT_BIT_RATE}. */
    public static function bitRate(): int
    {
        $rate = (int) config('twa.tts.deepgram.bit_rate', self::DEFAULT_BIT_RATE);

        return in_array($rate, self::BIT_RATES, true) ? $rate : self::DEFAULT_BIT_RATE;
    }

    /** A voice name this driver can send (see {@see VOICE_PATTERN}). */
    public static function supportsVoice(string $voice): bool
    {
        return preg_match(self::VOICE_PATTERN, $voice) === 1;
    }

    /**
     * The code Deepgram refused with (`tts_auth_failed` | `tts_forbidden` | `tts_payment_required`) while the halt marker is set,
     * else null. One GET on the default cache store: the reconciliation, `audio:sample` and the dashboard ask it.
     */
    public static function haltCode(): ?string
    {
        $marker = Cache::get(self::HALT_KEY);
        // docs/19 §3.11: a cached value is checked, not trusted.
        $code = is_array($marker) ? ($marker['code'] ?? null) : null;

        return in_array($code, self::HALT_CODES, true) ? $code : null;
    }

    /** Drops the halt marker (`audio:sample --clear-halt`, after the key was replaced or the account topped up). */
    public static function clearHalt(): bool
    {
        return Cache::forget(self::HALT_KEY);
    }

    private function throwIfHalted(): void
    {
        match (self::haltCode()) {
            SpeechSynthesisException::AUTH_FAILED => throw SpeechSynthesisException::authFailed(),
            SpeechSynthesisException::FORBIDDEN => throw SpeechSynthesisException::forbidden(),
            SpeechSynthesisException::PAYMENT_REQUIRED => throw SpeechSynthesisException::paymentRequired(),
            default => null,
        };
    }

    private function halt(SpeechSynthesisException $e): SpeechSynthesisException
    {
        Cache::put(self::HALT_KEY, ['code' => $e->getMessage()], self::HALT_SECONDS);

        return $e;
    }
}
