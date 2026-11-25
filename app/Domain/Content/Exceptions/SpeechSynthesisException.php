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
    /**
     * No synthesizer is configured (`TTS_DRIVER=null`, the `http` driver without `TTS_SERVICE_URL`, `google` without a key path,
     * `deepgram` without `DEEPGRAM_API_KEY`): nothing to retry.
     */
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
     * The voice is not one of the driver (`TTS_VOICE=en-US-AriaNeural` of edge-tts with `TTS_DRIVER=google`, a voice of Google with
     * `TTS_DRIVER=deepgram`): every word would be a 400. Final, refused before the service is called and before the characters are
     * reserved (docs/26 §9.2).
     */
    public const VOICE_UNSUPPORTED = 'tts_voice_unsupported';

    /** 401 even with a freshly signed token (`deepgram`: 401 - a wrong or deleted key): final, and the driver halts for a while (docs/26 §9.1). */
    public const AUTH_FAILED = 'tts_auth_failed';

    /** 403 (API disabled, no permission, billing; `deepgram`: the key may not synthesize): final, and the driver halts for a while. */
    public const FORBIDDEN = 'tts_forbidden';

    /** The key file of the service account is missing, unreadable or not a service-account RSA key: final. */
    public const CREDENTIALS_INVALID = 'tts_credentials_invalid';

    /** The character budget of the month is spent (docs/26 §9.3): final for this month. */
    public const MONTHLY_LIMIT_REACHED = 'tts_monthly_limit_reached';

    /**
     * The `deepgram` driver: the characters of `DEEPGRAM_TTS_CHAR_LIMIT` are spent - a limit on the whole one-off credit, not on a
     * month (docs/26 §10): final until the owner raises the limit.
     */
    public const CREDIT_LIMIT_REACHED = 'tts_credit_limit_reached';

    /** The `deepgram` driver: 402, the account has no money or credit left. Final, and the driver halts for a while (docs/26 §10). */
    public const PAYMENT_REQUIRED = 'tts_payment_required';

    /** The text is longer than one request of the service takes (`deepgram`: `DEEPGRAM_TTS_MAX_CHARS`): final, nothing is sent. */
    public const TEXT_TOO_LONG = 'tts_text_too_long';

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

    public static function creditLimitReached(): self
    {
        return new self(self::CREDIT_LIMIT_REACHED, false);
    }

    public static function paymentRequired(): self
    {
        return new self(self::PAYMENT_REQUIRED, false);
    }

    public static function textTooLong(): self
    {
        return new self(self::TEXT_TOO_LONG, false);
    }

    /** A spent character budget of the driver (the month of `google`, the credit of `deepgram`): the word job ends quietly. */
    public function isBudgetSpent(): bool
    {
        return in_array($this->getMessage(), [self::MONTHLY_LIMIT_REACHED, self::CREDIT_LIMIT_REACHED], true);
    }
}
