<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;

/** `TTS_DRIVER=null` (docs/26 §5.1): nothing is configured, every synthesis is the final `tts_service_not_configured`. */
final class NullSpeechSynthesizer implements SpeechSynthesizer
{
    public function synthesize(string $text, string $voice): array
    {
        throw SpeechSynthesisException::notConfigured();
    }
}
