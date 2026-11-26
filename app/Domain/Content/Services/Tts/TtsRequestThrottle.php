<?php

declare(strict_types=1);

namespace App\Domain\Content\Services\Tts;

use App\Domain\Content\Exceptions\SpeechSynthesisException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * The pace of the calls to Google (docs/26 §9.3): at most `twa.tts.rate_per_minute` (default 150, under the quota of 200
 * for Chirp 3 HD) across all workers; the calls to Deepgram (docs/26 §10) count on a limiter of their own,
 * `twa.tts.deepgram.rate_per_minute` (default 150). Over it the job WAITS inside the driver instead of being released back to the queue -
 * a release spends an attempt, so a first mass run would fail jobs that never called Google. A wait longer than 30 seconds
 * gives up with the retryable `tts_rate_limited`.
 *
 * The limiter lives on the default cache store (`redis`, not evicting - docs/19 §3.10). The check and the hit are not one
 * atomic step: a soft limit for a quota, not for money (that is {@see TtsCharacterBudget}).
 */
final class TtsRequestThrottle
{
    public const KEY = 'tts-google';

    public const DEEPGRAM_KEY = 'tts-deepgram';

    /** The longest total wait, in seconds. */
    public const MAX_WAIT = 30;

    /** The longest single pause, so a slot freed early is taken soon. */
    private const MAX_PAUSE = 5;

    /**
     * @param  string  $driver  `google` or `deepgram` ({@see ConfiguredSpeechSynthesizer}): whose limiter and pace
     *
     * @throws SpeechSynthesisException tts_rate_limited when no slot came free within {@see MAX_WAIT} seconds
     */
    public function acquire(string $driver = ConfiguredSpeechSynthesizer::GOOGLE): void
    {
        [$key, $perMinute] = $driver === ConfiguredSpeechSynthesizer::DEEPGRAM
            ? [self::DEEPGRAM_KEY, (int) config('twa.tts.deepgram.rate_per_minute', 150)]
            : [self::KEY, (int) config('twa.tts.rate_per_minute', 150)];
        $perMinute = max(1, $perMinute);
        $waited = 0;

        while (! RateLimiter::attempt($key, $perMinute, static fn (): bool => true, 60)) {
            if ($waited >= self::MAX_WAIT) {
                throw SpeechSynthesisException::rateLimited();
            }

            $pause = max(1, min(RateLimiter::availableIn($key), self::MAX_PAUSE, self::MAX_WAIT - $waited));
            Sleep::for($pause)->seconds();
            $waited += $pause;
        }
    }
}
