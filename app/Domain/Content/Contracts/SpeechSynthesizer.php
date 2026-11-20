<?php

declare(strict_types=1);

namespace App\Domain\Content\Contracts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\WordAudioService;
use App\Providers\AppServiceProvider;

/**
 * Text to speech (docs/26 §5.1): the word audio ({@see WordAudioService}), the dictations of the
 * practice and the live `GET /api/twa/tts` fallback all voice through it. The driver is `twa.tts.driver` (`TTS_DRIVER`), bound
 * in {@see AppServiceProvider}: `http` (the edge-tts compatible service of `TTS_SERVICE_URL`) or `null`
 * (nothing configured).
 */
interface SpeechSynthesizer
{
    /**
     * `$live` is a synthesis on behalf of an API request: the driver answers fast (`twa.tts.live_timeout`,
     * `twa.tts.live_connect_timeout`) instead of the long timeout of the pre-generation jobs.
     *
     * @return array{body:string, content_type:string}
     *
     * @throws SpeechSynthesisException the message is a stable code; `retryable` tells a job whether another attempt may help
     */
    public function synthesize(string $text, string $voice, bool $live = false): array;
}
