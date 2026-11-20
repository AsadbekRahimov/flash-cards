<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use RuntimeException;
use Throwable;

/**
 * A failed {@see SpeechSynthesizer::synthesize()}. The message is a stable code (it goes into
 * `audio_error` and the logs, never a URL); `retryable` is false for a state of the installation that another attempt cannot
 * change (nothing configured): a job fails at once instead of retrying.
 */
final class SpeechSynthesisException extends RuntimeException
{
    /** No synthesizer is configured (`TTS_DRIVER=null`, or the `http` driver without `TTS_SERVICE_URL`): nothing to retry. */
    public const NOT_CONFIGURED = 'tts_service_not_configured';

    /** The service refused or could not be reached: the queue retries with the backoff of the job. */
    public const FAILED = 'tts_service_failed';

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
}
