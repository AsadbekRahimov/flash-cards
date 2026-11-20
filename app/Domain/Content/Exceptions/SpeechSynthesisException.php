<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use RuntimeException;
use Throwable;

/**
 * A failed {@see SpeechSynthesizer::synthesize()}. The message is a stable code (it goes into
 * `audio_error` and the logs, never a URL, a key, a token or a body of the service); `retryable` is false for a state that
 * another attempt cannot change (nothing configured, a refused request, a revoked key, the monthly limit): a job fails at
 * once instead of retrying. The codes and what a job does with each - docs/26 §9.2.
 */
final class SpeechSynthesisException extends RuntimeException
{
    /** No synthesizer is configured (`TTS_DRIVER=null`, the `http` driver without `TTS_SERVICE_URL`, `google` without a key path): nothing to retry. */
    public const NOT_CONFIGURED = 'tts_service_not_configured';

    /** The service failed (5xx) or could not be reached: the queue retries with the backoff of the job. */
    public const FAILED = 'tts_service_failed';

    /** The service answered, but not with usable audio: retried. */
    public const BAD_AUDIO = 'tts_service_bad_audio';

    /** 408 / 429 of the service or our own pace limit could not be waited out: retried. */
    public const RATE_LIMITED = 'tts_rate_limited';

    /** The service refused the request itself (400, 404, other 4xx; a voice name or a text it would refuse): final. */
    public const REJECTED = 'tts_request_rejected';

    /**
     * The voice is not one of Google's (`TTS_VOICE=en-US-AriaNeural` of edge-tts with `TTS_DRIVER=google`): every word would be a
     * 400. Final, refused before Google is called and before the characters are reserved (docs/26 §9.2).
     */
    public const VOICE_UNSUPPORTED = 'tts_voice_unsupported';

    /** 401 even with a freshly signed token: final, and the driver halts for a while (docs/26 §9.1). */
    public const AUTH_FAILED = 'tts_auth_failed';

    /** 403 (API disabled, no permission, billing): final, and the driver halts for a while. */
    public const FORBIDDEN = 'tts_forbidden';

    /** The key file of the service account is missing, unreadable or not a service-account RSA key: final. */
    public const CREDENTIALS_INVALID = 'tts_credentials_invalid';

    /** The character budget of the month is spent (docs/26 §9.3): final for this month. */
    public const MONTHLY_LIMIT_REACHED = 'tts_monthly_limit_reached';

    private function __construct(string $code, public readonly bool $retryable, ?Throwable $previous = null)
    {
        parent::__construct($code, previous: $previous);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, false);
    }

    public static function failed(?Throwable $previous = null): self
    {
        return new self(self::FAILED, true, $previous);
    }

    public static function badAudio(): self
    {
        return new self(self::BAD_AUDIO, true);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED, true);
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED, false);
    }

    public static function voiceUnsupported(): self
    {
        return new self(self::VOICE_UNSUPPORTED, false);
    }

    public static function authFailed(): self
    {
        return new self(self::AUTH_FAILED, false);
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, false);
    }

    /** Never with a `previous`: the cause may quote the key file. */
    public static function credentialsInvalid(): self
    {
        return new self(self::CREDENTIALS_INVALID, false);
    }

    public static function monthlyLimitReached(): self
    {
        return new self(self::MONTHLY_LIMIT_REACHED, false);
    }
}
