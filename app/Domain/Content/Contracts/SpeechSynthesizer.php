<?php

declare(strict_types=1);

namespace App\Domain\Content\Contracts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\WordAudioService;
use App\Providers\AppServiceProvider;

/**
 * Text to speech (docs/26 §5.1): the word audio ({@see WordAudioService}) and the dictations of the practice voice through
 * it, always from a queued job - no request of a learner synthesizes (docs/26 §9.4). The driver is `twa.tts.driver`
 * (`TTS_DRIVER`), bound in {@see AppServiceProvider}: `deepgram` (Deepgram Aura-2, the main one - docs/26 §10), `google`
 * (Google Cloud Text-to-Speech), `http` (the edge-tts compatible service of `TTS_SERVICE_URL`) or `null` (nothing configured).
 */
interface SpeechSynthesizer
{
    /**
     * @return array{body:string, content_type:string}
     *
     * @throws SpeechSynthesisException the message is a stable code; `retryable` tells a job whether another attempt may help
     */
    public function synthesize(string $text, string $voice): array;
}
