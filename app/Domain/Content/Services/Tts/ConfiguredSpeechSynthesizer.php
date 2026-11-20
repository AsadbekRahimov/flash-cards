<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Contracts\SpeechSynthesizer;
use App\Domain\Content\Exceptions\SpeechSynthesisException;
use App\Domain\Content\Services\WordAudioService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * The binding of {@see SpeechSynthesizer} (docs/26 §5.1): picks the driver of `twa.tts.driver` (`TTS_DRIVER`) at the moment of a
 * synthesis, not when a service that needs a synthesizer is built. A typo in `TTS_DRIVER` therefore breaks nothing but the
 * synthesis itself - the services of the screens of a learner ({@see WordAudioService},
 * the practice audio) take this object in their constructors and keep working; every synthesis fails with the final
 * `tts_service_not_configured` and a warning in the log, `audio:sample` and `/health/ready` say so.
 *
 * The driver is compared without case and surrounding blanks. Unset (or blank): `http` when `TTS_SERVICE_URL` is set, else `null`
 * (installations from before the driver keep working).
 */
final class ConfiguredSpeechSynthesizer implements SpeechSynthesizer
{
    public const GOOGLE = 'google';

    public const HTTP = 'http';

    public const NONE = 'null';

    /** @var list<string> */
    public const KNOWN = [self::GOOGLE, self::HTTP, self::NONE];

    public function __construct(private readonly Container $app) {}

    /** The driver in effect: `google`, `http`, `null` - or the unknown value as it was written, lower-cased and trimmed. */
    public static function driver(): string
    {
        $driver = strtolower(trim((string) config('twa.tts.driver')));
        if ($driver === '') {
            return trim((string) config('twa.tts.service_url')) !== '' ? self::HTTP : self::NONE;
        }

        return $driver;
    }

    /** False for a `TTS_DRIVER` that is none of google, http or null (a typo). */
    public static function known(): bool
    {
        return in_array(self::driver(), self::KNOWN, true);
    }

    /**
     * The synthesizer of the driver in effect, built now (a `config()` change applies at once).
     *
     * @throws SpeechSynthesisException tts_service_not_configured for an unknown driver
     */
    public function resolve(): SpeechSynthesizer
    {
        return match (self::driver()) {
            self::GOOGLE => $this->app->make(GoogleSpeechSynthesizer::class),
            self::HTTP => $this->app->make(HttpSpeechSynthesizer::class),
            self::NONE => $this->app->make(NullSpeechSynthesizer::class),
            default => throw $this->unknownDriver(),
        };
    }

    public function synthesize(string $text, string $voice): array
    {
        return $this->resolve()->synthesize($text, $voice);
    }

    private function unknownDriver(): SpeechSynthesisException
    {
        Log::warning('tts.driver_unknown', [
            // The value of an environment variable, not a secret; only its harmless characters, at most 32 of them.
            'driver' => substr((string) preg_replace('/[^a-z0-9_.-]/', '?', self::driver()), 0, 32),
            'expected' => self::KNOWN,
        ]);

        return SpeechSynthesisException::notConfigured();
    }
}
